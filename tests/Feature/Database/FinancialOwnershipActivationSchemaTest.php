<?php

namespace Tests\Feature\Database;

use App\Services\ResidentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresFeatureTestCase;

#[Group('billing-expansion-schema')]
class FinancialOwnershipActivationSchemaTest extends PostgresFeatureTestCase
{
    public function test_gate_off_works_on_old_schema_and_gate_on_fails_closed_without_raw_missing_table_error(): void
    {
        (require database_path('migrations/2026_11_12_000008_expand_billing_schema.php'))->down();
        $this->assertFalse(config('financial_ownership.enabled'));
        $admin = $this->account();
        $family = $this->account('keluarga');
        $data = ['name' => 'Fixture old schema', 'birth_date' => '1950-01-01', 'family_id' => $family->id];
        app(ResidentService::class)->create($admin, $data);
        $this->assertSame(1, DB::table('elderlies')->count());
        $this->assertNull(DB::scalar("SELECT to_regclass('public.financial_ownership_periods')"));
        config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-test-only']);
        try {
            app(ResidentService::class)->create($admin, $data);
            $this->fail('Gate ON schema lama harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }
        $this->assertSame(1, DB::table('elderlies')->count());
    }
}
