<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentHttpReader;
use App\Support\Billing\PaymentUiContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class PaymentPageController extends Controller
{
    public function __invoke(Request $request, PaymentUiContext $context, PaymentHttpReader $reader)
    {
        $invoice = $request->route('invoice');
        $payment = $request->route('payment');
        if ($invoice) {
            $reader->invoice($request->user(), $invoice);
            if ($payment) {
                $reader->payment($request->user(), $invoice, $payment);
            }
        }
        $role = $request->user()->role;
        $page = $role === 'admin' ? 'Admin/Payments/' : 'Family/Payments/';
        $factory = clone Inertia::getFacadeRoot();
        $factory->resolveUrlUsing(fn (Request $r) => $r->getBaseUrl().$r->getPathInfo());

        return $factory->render($page.($invoice ? 'Show' : 'Index'), ['payment_ui_context' => $context->forRequest($request),
            'invoice_id' => $invoice, 'payment_id' => $payment]);
    }
}
