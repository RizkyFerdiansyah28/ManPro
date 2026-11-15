<?php

namespace Tests\Feature\Database;

use App\Services\Billing\InvoiceIssuanceFailure;
use App\Services\Billing\InvoiceIssuanceReadiness;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresFeatureTestCase;

#[Group('billing-expansion-schema')]
class InvoiceIssuanceActivationSchemaTest extends PostgresFeatureTestCase
{
    public function test_schema_a_without_b_guards_cannot_activate_issuance(): void
    {
        $this->assertNull(DB::scalar("SELECT to_regprocedure('public.billing_validate_invoice(uuid)')"));
        config(['invoice_issuance.enabled' => true, 'financial_ownership.enabled' => true,
            'financial_ownership.cutover_reference' => 'owned-test-only-approval']);
        $before = DB::table('invoices')->count();
        try {
            app(InvoiceIssuanceReadiness::class)->transaction();
            $this->fail('Tanpa guard B harus fail closed.');
        } catch (InvoiceIssuanceFailure $failure) {
            $this->assertSame('ledger_unavailable', $failure->kind);
        }
        $this->assertSame($before, DB::table('invoices')->count());
    }
}
