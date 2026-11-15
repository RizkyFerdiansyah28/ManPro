<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\InvoiceHttpRequest;
use App\Models\Invoice;
use App\Services\Billing\InvoiceHttpReadService;
use App\Support\Billing\InvoiceRecoveryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(InvoiceHttpRequest $request, InvoiceHttpReadService $reader)
    {
        $filters = $request->validated();

        return $this->page($request, 'Index', ['invoices' => $reader->index($request->user()->id, $filters), 'filters' => $filters]);
    }

    public function create(InvoiceHttpRequest $request, InvoiceHttpReadService $reader, InvoiceRecoveryContext $recovery)
    {
        $reader->context($request->user()->id, $request->validated('elderly_id'));

        return $this->page($request, 'Create', ['filters' => $request->validated(), 'frontend_available' => false,
            'recovery_context' => $recovery->forRequest($request)]);
    }

    public function show(InvoiceHttpRequest $request, string $invoice, InvoiceHttpReadService $reader)
    {
        Gate::authorize('view', [Invoice::class, $invoice]);

        return $this->page($request, 'Show', ['invoice' => $reader->show($request->user()->id, $invoice)]);
    }

    public function residentOptions(InvoiceHttpRequest $request, InvoiceHttpReadService $reader)
    {
        return response()->json($reader->residentOptions($request->user()->id, $request->validated()));
    }

    private function page(Request $request, string $page, array $props)
    {
        $factory = clone Inertia::getFacadeRoot();
        $factory->resolveUrlUsing(fn (Request $r) => $r->getBaseUrl().$r->getPathInfo()
            .($request->validated() !== [] ? '?'.http_build_query($request->validated()) : ''));

        return $factory->render('Admin/Billing/Invoices/'.$page, $props);
    }
}
