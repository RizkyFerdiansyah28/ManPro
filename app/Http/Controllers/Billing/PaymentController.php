<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PaymentHttpRequest;
use App\Services\Billing\LocalPaymentProofStore;
use App\Services\Billing\PaymentHttpReader;
use App\Services\Billing\PaymentHttpService;
use App\Services\Billing\PaymentResult;
use App\Services\Billing\StoredPaymentProof;
use App\Support\Billing\PaymentCommandContext;
use Illuminate\Support\Facades\DB;

final class PaymentController extends Controller
{
    public function index(PaymentHttpRequest $request, PaymentHttpReader $reader)
    {
        return response()->json($reader->index($request->user(), $request->validated()));
    }

    public function showInvoice(PaymentHttpRequest $request, string $invoice, PaymentHttpReader $reader)
    {
        return response()->json($reader->detail($request->user(), $invoice));
    }

    public function showPayment(PaymentHttpRequest $request, string $invoice, string $payment, PaymentHttpReader $reader)
    {
        return response()->json($reader->reviewDetail($request->user(), $invoice, $payment));
    }

    public function context(PaymentHttpRequest $request, string $invoice, PaymentHttpService $service, ?string $payment = null)
    {
        return response()->json($service->context($request, $invoice, $payment));
    }

    public function submit(PaymentHttpRequest $request, string $invoice, PaymentHttpService $service)
    {
        $result = $service->submit($request, $invoice);

        return response()->json($this->result($result), $result->replayed ? 200 : 201);
    }

    public function verify(PaymentHttpRequest $request, string $invoice, string $payment, PaymentHttpService $service)
    {
        return response()->json($this->result($service->review($request, $invoice, $payment, 'verify')));
    }

    public function reject(PaymentHttpRequest $request, string $invoice, string $payment, PaymentHttpService $service)
    {
        return response()->json($this->result($service->review($request, $invoice, $payment, 'reject')));
    }

    public function recoveryContext(PaymentHttpRequest $request, PaymentCommandContext $contexts)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'keluarga'], true), 403);

        return response()->json(['recovery_context' => $contexts->issue($request, 'recovery'), 'expires_in' => 300]);
    }

    public function reconcile(PaymentHttpRequest $request, PaymentHttpService $service)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'keluarga'], true), 403);

        return response()->json($this->result($service->reconcile($request)));
    }

    public function proof(PaymentHttpRequest $request, string $invoice, string $payment, PaymentHttpReader $reader, LocalPaymentProofStore $proofs)
    {
        // Read authorization and bytes under a single ownership snapshot.
        $reader->invoice($request->user(), $invoice);

        return DB::transaction(function () use ($request, $invoice, $payment, $reader, $proofs) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            DB::statement("SET LOCAL statement_timeout='10s'");
            $row = $reader->payment($request->user(), $invoice, $payment);
            $proof = StoredPaymentProof::fromRow($row);
            $bytes = $proofs->bytes($proof, $row->submitted_by, $invoice);

            return response($bytes)->header('Content-Type', $proof->mime)->header('Content-Length', (string) strlen($bytes))
                ->header('Content-Disposition', 'attachment; filename="bukti.'.($proof->mime === 'image/png' ? 'png' : 'jpg').'"');
        });
    }

    private function result(PaymentResult $result): array
    {
        return ['command_fact' => $result->fact(), 'invoice_id' => $result->invoiceId, 'revision_id' => $result->revisionId,
            'payment_id' => $result->paymentId, 'amount' => $result->amount, 'recorded_at' => $result->recordedAt, 'replayed' => $result->replayed];
    }
}
