<?php

namespace Tests\Feature\Database;

use App\Models\Invoice;
use App\Services\Billing\InvoiceDecision;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceReason;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceIssuanceFeatureTestCase;

#[Group('invoice-issuance')]
class InvoiceIssuanceReadinessTest extends InvoiceIssuanceFeatureTestCase
{
    public static function legacyStates(): array
    {
        return [['unpaid'], ['paid'], ['cancelled']];
    }

    #[DataProvider('legacyStates')]
    public function test_existing_legacy_root_is_never_promoted_or_replaced(string $status): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $legacy = Invoice::create(['number' => 'LEGACY-'.$this->billingId(), 'elderly_id' => $s['elderly']->id,
            'service_enrollment_id' => $s['enrollment']->id, 'billing_month' => '2026-10-01', 'amount' => '1.00', 'currency' => 'IDR',
            'due_date' => '2026-11-08', 'grace_period_ends_on' => '2026-11-11', 'status' => $status,
            'paid_at' => $status === 'paid' ? '2026-11-01 00:00:00+07' : null]);
        $before = $this->fingerprints();
        $this->assertFailure('existing_root_conflict', fn () => app(InvoiceIssuanceService::class)->issue($command));
        $this->assertNull(DB::table('invoices')->where('id', $legacy->id)->value('billing_schema_version'));
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_empty_cutover_reference_cannot_be_used_as_activation_approval(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        config(['financial_ownership.cutover_reference' => null]);
        $before = $this->fingerprints();
        $this->assertFailure('ledger_unavailable', fn () => app(InvoiceIssuanceService::class)->issue($command));
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_wrong_environment_connection_and_missing_owner_context_are_rejected_before_mutating(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $before = $this->fingerprints();
        config(['database.default' => 'pgsql']);
        try {
            // Koneksi pgsql/manpro_db tidak di-query. Entry menolak default yang bukan test.
            $this->assertFailure('owned_test_database_required', fn () => app(InvoiceIssuanceService::class)->issue($command));
        } finally {
            config(['database.default' => 'silansia_testing']);
        }
        $this->app->instance('env', 'local');
        try {
            $this->assertFailure('owned_test_database_required', fn () => app(InvoiceIssuanceService::class)->issue($command));
        } finally {
            $this->app->instance('env', 'testing');
        }
        $identity = getenv('SILANSIA_TEST_IDENTITY');
        putenv('SILANSIA_TEST_IDENTITY=');
        try {
            $this->assertFailure('owned_test_database_required', fn () => app(InvoiceIssuanceService::class)->issue($command));
        } finally {
            putenv('SILANSIA_TEST_IDENTITY='.$identity);
        }
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_current_owner_inconsistent_with_committed_ledger_is_held_without_repair(): void
    {
        $s = $this->source();
        // Fixture legacy inconsistency: elderlies tidak mempunyai trigger B; ledger tetap immutable.
        DB::table('elderlies')->where('id', $s['elderly']->id)->update(['family_id' => $s['next']->id]);
        $before = $this->fingerprints();
        $result = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $this->assertSame(InvoiceDecision::Hold, $result->decision);
        $this->assertSame(InvoiceReason::StaleOwner, $result->reason);
        $this->assertNull($result->amount);
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_ownership_change_stales_confirmation_even_when_old_month_still_eligible(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $this->sourceTransfer($s, '2026-11-01 00:00:00.123456+07');
        $before = $this->fingerprints();
        $this->assertFailure('stale_preview', fn () => app(InvoiceIssuanceService::class)->issue($command));
        $this->assertEquals($before, $this->fingerprints());
        $fresh = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $this->assertSame(InvoiceDecision::Issue, $fresh->decision);
        $this->assertSame($s['family']->id, DB::table('invoices')->where('id', $fresh->invoiceId)->value('bill_to_family_id'));
    }

    public function test_unfinished_month_is_rejected_and_never_reserves_a_root(): void
    {
        $s = $this->source();
        $month = '9999-12-01';
        $preview = app(InvoiceIssuanceService::class)->preview($s['admin']->id, $s['elderly']->id, $month);
        $command = new InvoiceIssueCommand($s['admin']->id, $s['elderly']->id, $month, $this->billingId(), $preview->confirmation);
        $before = $this->fingerprints();
        $result = app(InvoiceIssuanceService::class)->issue($command);
        $this->assertSame(InvoiceDecision::Reject, $result->decision);
        $this->assertSame(InvoiceReason::MonthNotEnded, $result->reason);
        $this->assertNull($result->amount);
        $this->assertEquals($before, $this->fingerprints());
    }

    public function test_owner_account_checks_do_not_replace_no_service_priority_or_require_active_family(): void
    {
        $s = $this->source(['no_service' => true]);
        DB::table('users')->where('id', $s['family']->id)->update(['role' => 'perawat']);
        $result = app(InvoiceIssuanceService::class)->issue($this->command($s));
        $this->assertSame(InvoiceDecision::Skip, $result->decision);
        $this->assertSame(InvoiceReason::NoService, $result->reason);
        $valid = $this->source();
        DB::table('users')->where('id', $valid['family']->id)->update(['is_active' => false]);
        $result = app(InvoiceIssuanceService::class)->issue($this->command($valid));
        $this->assertSame(InvoiceDecision::Issue, $result->decision);
        $invalid = $this->source();
        DB::table('users')->where('id', $invalid['family']->id)->update(['role' => 'perawat']);
        $before = $this->fingerprints();
        $result = app(InvoiceIssuanceService::class)->issue($this->command($invalid));
        $this->assertSame(InvoiceDecision::Hold, $result->decision);
        $this->assertSame(InvoiceReason::InvalidChain, $result->reason);
        $this->assertNull($result->amount);
        $this->assertEquals($before, $this->fingerprints());
    }

    public static function transientCases(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('transientCases')]
    public function test_whole_transaction_retry_is_bounded_and_retains_one_durable_intent(bool $once): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $sequence = $this->transientFault($once);
        $before = $this->fingerprints();
        if ($once) {
            $result = app(InvoiceIssuanceService::class)->issue($command);
            $this->assertSame(InvoiceDecision::Issue, $result->decision);
            $this->assertSame(1, DB::table('invoices')->where('elderly_id', $s['elderly']->id)->count());
            $this->assertSame(1, DB::table('billing_requests')->where('actor_id', $command->actorId)->where('request_id', $command->requestId)->count());
        } else {
            $this->assertFailure('transient_retry_exhausted', fn () => app(InvoiceIssuanceService::class)->issue($command), '40P01');
            $this->assertEquals($before, $this->fingerprints());
        }
        $this->assertSame(2, DB::scalar("SELECT last_value::int FROM public.{$sequence}"));
    }
}
