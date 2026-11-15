<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\PostgresFeatureTestCase;

#[Group('billing-expansion-schema')]
class BillingIntegrityMigrationTest extends PostgresFeatureTestCase
{
    use BillingIntegrityFixtures;

    public function test_integrity_preflight_is_atomic_and_never_repairs_invalid_existing_graph(): void
    {
        $g = $this->billingGraph();
        DB::table('invoices')->where('id', $g['invoice']['id'])->update(['amount' => '1.00']);
        $records = DB::table('migrations')->orderBy('id')->get()->all();
        $rows = DB::table('invoices')->where('id', $g['invoice']['id'])->first();
        $migration = require database_path('migrations/2026_11_13_000009_add_billing_integrity_guards.php');
        try {
            $migration->up();
            $this->fail('Preflight wajib menolak graph invalid.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('billing_invoice_projection', $exception->errorInfo[2]);
        }
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname LIKE 'billing_%'"));
        $this->assertNull(DB::scalar("SELECT to_regclass('public.billing_requests_invoice_index')"));
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get()->all());
        $this->assertEquals($rows, DB::table('invoices')->where('id', $g['invoice']['id'])->first());
        DB::table('invoices')->where('id', $g['invoice']['id'])->update(['amount' => '3100.00']);
        $migration->up();
        $this->assertSame(18, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'billing_%'"));
        try {
            $migration->down();
            $this->fail('Down wajib mempertahankan guard histori existing.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame('Rollback guard billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.', $exception->getMessage());
        }
        $this->assertSame(18, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'billing_%'"));
    }
}
