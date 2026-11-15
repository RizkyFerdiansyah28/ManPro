<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\InvoiceHttpRequest;
use App\Http\Resources\Billing\InvoicePreviewResource;
use App\Services\Billing\InvoiceDecision;
use App\Services\Billing\InvoiceHttpReadService;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueResult;
use App\Services\Billing\InvoiceReason;
use App\Support\Billing\InvoiceConfirmationToken;

class InvoiceIssuanceController extends Controller
{
    public function preview(InvoiceHttpRequest $request, InvoiceHttpReadService $reader, InvoiceIssuanceService $service, InvoiceConfirmationToken $tokens)
    {
        $actor = $request->user()->id;
        $resident = strtolower($request->validated('elderly_id'));
        $month = $request->validated('billing_month');
        $reader->context($actor, $resident);
        $preview = $service->preview($actor, $resident, $month);
        $confirmation = $preview->eligibility->decision === InvoiceDecision::Issue
            ? $tokens->mint($actor, $resident, $month, $preview->confirmation->fingerprint) : null;

        return response()->json(InvoicePreviewResource::make($preview, $confirmation));
    }

    public function store(InvoiceHttpRequest $request, InvoiceConfirmationToken $tokens, InvoiceIssuanceService $service, InvoiceHttpReadService $reader)
    {
        $reader->context($request->user()->id);
        $command = $tokens->command($request->user()->id, $request->validated('elderly_id'), $request->validated('billing_month'),
            $request->validated('request_id'), $request->validated('confirmation_token'));

        return $this->result($service->issue($command));
    }

    public function reconcile(InvoiceHttpRequest $request, InvoiceConfirmationToken $tokens, InvoiceHttpReadService $reader, InvoiceIssuanceService $service)
    {
        $command = $tokens->command($request->user()->id, $request->validated('elderly_id'), $request->validated('billing_month'),
            $request->validated('request_id'), $request->validated('confirmation_token'), reconcile: true);
        // Proof obligation: completed request is append-only (UPDATE/DELETE/TRUNCATE forbidden),
        // guarded/pinned before lookup AND by the unchanged service before locked replay.
        // No candidate => never call issue. No outer transaction around the service.
        if (! $reader->committedRequestExists($command->actorId, $command->requestId)) {
            throw new InvoiceHttpException('outcome_unresolved');
        }
        $result = $service->issue($command);
        if (! $result->replayed) {
            // Defensive assertion, NOT the protection against initial insertion. Immutability above
            // is the protection and is verified with independent workers/guards in the owned suite.
            throw new InvoiceHttpException('integrity_violation');
        }

        return $this->result($result);
    }

    private function result(InvoiceIssueResult $result)
    {
        if ($result->decision !== InvoiceDecision::Issue) {
            throw new InvoiceHttpException(match (true) {
                $result->decision === InvoiceDecision::Hold => 'held', $result->decision === InvoiceDecision::Skip => 'no_service',
                in_array($result->reason, [InvoiceReason::InvalidClock, InvoiceReason::Overflow, InvoiceReason::UnsupportedRuntime], true) => 'billing_unavailable',
                default => 'rejected',
            });
        }

        return response()->json(['kind' => 'issued', 'issued' => true, 'decision' => 'ISSUE', 'invoice_id' => $result->invoiceId,
            'revision_id' => $result->revisionId, 'amount' => $result->amount, 'issued_at' => $result->issuedAt,
            'replayed' => $result->replayed, 'detail_url' => route('admin.billing.invoices.show', $result->invoiceId, absolute: false)], $result->replayed ? 200 : 201);
    }
}
