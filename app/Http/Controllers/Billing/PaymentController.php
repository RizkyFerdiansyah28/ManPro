<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PaymentHttpRequest;
use App\Services\Billing\PaymentHttpReader;
use App\Services\Billing\PaymentHttpService;
use App\Services\Billing\PaymentResult;
use App\Services\Billing\ProtectedPaymentProof;
use App\Support\Billing\PaymentCommandContext;

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

    public function proof(PaymentHttpRequest $request, string $invoice, string $payment, ProtectedPaymentProof $proofs)
    {
        abort_unless($request->getRealMethod() === 'GET' && $request->isMethod('GET'), 405, '', ['Allow' => 'GET']);
        foreach (['Range', 'If-Range', 'If-Match', 'If-None-Match', 'If-Modified-Since', 'If-Unmodified-Since'] as $header) {
            abort_if($request->headers->has($header), 422);
        }

        ['bytes' => $bytes, 'mime' => $mime] = $proofs->download($request->user(), $invoice, $payment);

        return response($bytes)->header('Content-Type', $mime)->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="bukti.'.($mime === 'image/png' ? 'png' : 'jpg').'"');
    }

    private function result(PaymentResult $result): array
    {
        return ['command_fact' => $result->fact(), 'invoice_id' => $result->invoiceId, 'revision_id' => $result->revisionId,
            'payment_id' => $result->paymentId, 'amount' => $result->amount, 'recorded_at' => $result->recordedAt, 'replayed' => $result->replayed];
    }
}
