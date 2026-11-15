<?php

namespace Tests\Feature\Database;

use App\Http\Requests\Operations\CreateEnrollmentRequest;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceConcurrencyTestCase;

#[Group('invoice-temporal')]
class InvoiceTemporalCoverageTest extends InvoiceConcurrencyTestCase
{
    protected string $evidenceEnvironment = 'SILANSIA_P92D1_EVIDENCE_FILE';

    protected string $evidencePrefix = 'SILANSIA_P9_2D1_TEST_EVIDENCE_';

    public static function transfers(): array
    {
        return [
            'M0' => ['2026-10-01', '2026-10-01 00:00:00.000000+07', [], 'ISSUE', true],
            'M1' => ['2026-10-01', '2026-11-01 00:00:00.000000+07', [], 'ISSUE', false],
            'M1-minus-us' => ['2026-10-01', '2026-10-31 23:59:59.999999+07', [], 'HOLD', false],
            'M1-plus-us' => ['2026-10-01', '2026-11-01 00:00:00.000001+07', [], 'ISSUE', false],
            'after-service-closed' => ['2026-10-01', '2026-10-20 12:00:00.123456+07', ['end' => '2026-10-05 00:00:00+07'], 'HOLD', false],
            'before-service-start' => ['2026-10-01', '2026-10-05 12:00:00.123456+07', ['start' => '2026-10-10 00:00:00+07'], 'HOLD', false],
            'year-M0' => ['2026-01-01', '2026-01-01 00:00:00.000000+07', ['from' => '2025-12-01 00:00:00+07', 'start' => '2026-01-01 00:00:00+07'], 'ISSUE', true],
            'year-M1' => ['2025-12-01', '2026-01-01 00:00:00.000000+07', ['from' => '2025-11-01 00:00:00+07', 'start' => '2025-12-01 00:00:00+07'], 'ISSUE', false],
            'leap-day' => ['2024-02-01', '2024-02-29 00:00:00.000000+07', ['from' => '2024-01-01 00:00:00+07', 'start' => '2024-02-01 00:00:00+07'], 'HOLD', false],
            'leap-M1' => ['2024-02-01', '2024-03-01 00:00:00.000000+07', ['from' => '2024-01-01 00:00:00+07', 'start' => '2024-02-01 00:00:00+07'], 'ISSUE', false],
        ];
    }

    #[DataProvider('transfers')]
    public function test_exact_historical_fixture_boundaries(string $month, string $at, array $changes, string $decision, bool $next): void
    {
        $s = $this->source($changes);
        $this->sourceTransfer($s, $at); // Fixture historis; tidak diklaim sebagai live writer.
        $this->assertTrue(DB::scalar('SELECT effective_from=CAST(? AS timestamptz) AND recorded_at=effective_from FROM financial_ownership_periods WHERE elderly_id=? AND previous_period_id IS NOT NULL', [$at, $s['elderly']->id]));
        $before = $this->fingerprints();
        $outcome = $this->finalDecision($s, $month, $decision, $decision === 'ISSUE' ? '3100.00' : null,
            $next ? $s['next']->id : $s['family']->id);
        $this->preserve($before, $this->billingTables());
        $this->evidence('R07-fixture-'.$month.'-'.$at, [$outcome], $before, ['historical_fixture_only' => true]);
    }

    public static function controlledTransfers(): array
    {
        return array_slice(self::transfers(), 0, 6, true);
    }

    #[DataProvider('controlledTransfers')]
    public function test_clock_controlled_transfer_first_with_real_parent_lock_wait(string $month, string $at, array $changes, string $decision, bool $next): void
    {
        $s = $this->source($changes);
        $old = $this->monthCommand($s, $month);
        $before = $this->fingerprints();
        $transfer = $this->spawn([...$this->transferPayload($s), 'ledger_clock' => $at, 'pause' => ['before_commit:1']]);
        $issuer = $this->spawn($this->issuePayload($old));
        $this->release($transfer);
        $this->awaitPoint($transfer, 'before_commit');
        $this->release($issuer);
        $overlap = $this->blockedBy($issuer, $transfer);
        $this->release($transfer);
        $a = $this->finish($transfer);
        $b = $this->finish($issuer);
        $this->assertTrue($a['ok']);
        $this->assertFalse($b['ok']);
        $this->assertSame('stale_preview', $b['kind']);
        $this->assertCount(0, $this->verifyInvoices([$s['elderly']->id]));
        $this->assertTrue(DB::scalar('SELECT effective_from=CAST(? AS timestamptz) FROM financial_ownership_periods WHERE elderly_id=? AND previous_period_id IS NOT NULL', [$at, $s['elderly']->id]));
        $fresh = $this->monthCommand($s, $month);
        $this->assertNotSame($old->confirmation->fingerprint, $fresh->confirmation->fingerprint);
        $outcome = $this->finalDecision($s, $month, $decision, $decision === 'ISSUE' ? '3100.00' : null,
            $next ? $s['next']->id : $s['family']->id);
        $this->preserve($before, [...$this->billingTables(), 'elderlies', 'financial_ownership_periods']);
        $this->evidence('R07-controlled-transfer-first-'.$at, [$a, $b, $outcome], $before, [...$overlap, 'ledger_clock_synthetic' => $at]);
    }

    public static function orders(): array
    {
        return ['transfer-first' => [false], 'issue-first' => [true]];
    }

    #[DataProvider('orders')]
    public function test_actual_clock_transfer_orders_preserve_frozen_historical_owner(bool $issueFirst): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $before = $this->fingerprints();
        $mutation = $this->transferPayload($s);
        $first = $this->spawn($issueFirst ? $this->issuePayload($command, ['before_commit:1']) : [...$mutation, 'pause' => ['before_commit:1']]);
        $second = $this->spawn($issueFirst ? $mutation : $this->issuePayload($command));
        $this->release($first);
        $this->awaitPoint($first, 'before_commit');
        $this->release($second);
        $overlap = $this->blockedBy($second, $first);
        $this->release($first);
        $a = $this->finish($first);
        $b = $this->finish($second);
        $this->assertTrue($a['ok']);
        if ($issueFirst) {
            $this->assertTrue($b['ok']);
            $this->assertSame('ISSUE', $a['decision']);
            $invoice = DB::table('invoices')->where('id', $a['invoice'])->sole();
            $this->assertSame($s['family']->id, $invoice->bill_to_family_id);
            $this->assertSame($s['period']['id'], $invoice->financial_ownership_period_id);
        } else {
            $this->assertFalse($b['ok']);
            $this->assertSame('stale_preview', $b['kind']);
            $this->finalDecision($s, '2026-10-01', 'ISSUE', '3100.00', $s['family']->id);
        }
        $this->assertTrue(DB::scalar("SELECT effective_from>='2026-11-01T00:00:00+07'::timestamptz FROM financial_ownership_periods WHERE elderly_id=? AND previous_period_id IS NOT NULL", [$s['elderly']->id]));
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        DB::select('SELECT public.billing_validate_ownership(?)', [$s['elderly']->id]);
        $this->preserve($before, [...$this->billingTables(), 'elderlies', 'financial_ownership_periods']);
        $this->evidence('R07-actual-clock-'.($issueFirst ? 'issue-first' : 'transfer-first'), [$a, $b], $before, $overlap);
    }

    public function test_controlled_a_to_b_to_a_remains_mixed_even_with_current_owner_restored(): void
    {
        $s = $this->source(['end' => '2026-10-05 00:00:00+07']);
        $before = $this->fingerprints();
        $outcomes = [];
        foreach ([['2026-10-10 00:00:00.123456+07', $s['next']->id, $s['family']->id], ['2026-10-20 00:00:00.123457+07', $s['family']->id, $s['next']->id]] as [$at, $family, $expected]) {
            $worker = $this->spawn([...$this->transferPayload($s), 'family' => $family, 'expected' => $expected, 'ledger_clock' => $at]);
            $this->release($worker);
            $outcomes[] = $this->finish($worker);
            $this->assertTrue(end($outcomes)['ok']);
        }
        $outcomes[] = $this->finalDecision($s, '2026-10-01', 'HOLD');
        $this->assertSame(3, DB::table('financial_ownership_periods')->where('elderly_id', $s['elderly']->id)->count());
        $this->assertSame($s['family']->id, $s['elderly']->fresh()->family_id);
        $this->preserve($before, [...$this->billingTables(), 'elderlies', 'financial_ownership_periods']);
        $this->evidence('R07-controlled-A-B-A', $outcomes, $before, ['controlled_clock_only' => true]);
    }

    public static function enrollments(): array
    {
        return [
            'before-M' => [['start' => '2026-09-10 12:00:00+07'], 'ISSUE', '3100.00'],
            'M0' => [[], 'ISSUE', '3100.00'],
            'M1' => [['start' => '2026-11-01 00:00:00+07'], 'SKIP', null],
            'after-M' => [['start' => '2026-11-02 00:00:00+07'], 'SKIP', null],
            'M1-minus-second' => [['start' => '2026-10-31 23:59:59+07'], 'ISSUE', '100.00'],
            'closed' => [['end' => '2026-10-05 00:00:00+07'], 'ISSUE', '400.00'],
            'closed-inactive-K02' => [['end' => '2026-10-05 00:00:00+07', 'active' => false], 'ISSUE', '400.00'],
            'multiple' => [['end' => '2026-10-05 00:00:00+07', 'multiple' => true], 'HOLD', null],
            'none' => [['no_service' => true], 'SKIP', null],
        ];
    }

    #[DataProvider('enrollments')]
    public function test_historical_enrollment_intervals_and_exact_amounts(array $changes, string $decision, ?string $amount): void
    {
        $s = $this->source($changes);
        if ($changes['multiple'] ?? false) {
            DB::table('service_enrollments')->insert(['id' => $this->billingId(), 'elderly_id' => $s['elderly']->id,
                'service_class_id' => $s['enrollment']->service_class_id, 'monthly_price' => '3100.00',
                'started_at' => '2026-10-05 00:00:00+07', 'ended_at' => '2026-11-01 00:00:00+07']);
        }
        $before = $this->fingerprints();
        $outcome = $this->finalDecision($s, '2026-10-01', $decision, $amount, $s['family']->id,
            ($changes['multiple'] ?? false) ? 'multiple_enrollments' : null);
        $this->preserve($before, $this->billingTables());
        $this->evidence('R09-fixture-'.json_encode($changes), [$outcome], $before, ['historical_fixture_only' => true]);
    }

    public static function creationTimes(): array
    {
        return [
            'actual-clock-now' => [null, 'SKIP', null],
            'controlled-before-M' => ['2026-09-10 12:00:00+07', 'ISSUE', '3100.00'],
            'controlled-M0' => ['2026-10-01 00:00:00+07', 'ISSUE', '3100.00'],
            'controlled-before-M1' => ['2026-10-31 23:59:59.999999+07', 'ISSUE', '100.00'],
            'controlled-M1' => ['2026-11-01 00:00:00+07', 'SKIP', null],
            'controlled-after-M' => ['2026-11-02 00:00:00+07', 'SKIP', null],
            'controlled-second-in-month' => ['2026-10-10 12:00:00+07', 'HOLD', null, ['end' => '2026-10-05 00:00:00+07']],
        ];
    }

    #[DataProvider('creationTimes')]
    public function test_enrollment_create_first_rejects_stale_preview_and_rechecks_chargeability(?string $at, string $decision, ?string $amount, array $changes = []): void
    {
        $s = $this->source($changes ?: ['no_service' => true]);
        $class = DB::table('service_classes')->where('code', 'vip')->sole();
        DB::table('service_classes')->where('id', $class->id)->update(['monthly_price' => '3100.00']);
        $old = $this->command($s);
        $before = $this->fingerprints();
        $payload = ['operation' => 'enroll', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id, 'class' => $class->id,
            'pause' => ['before_commit:1']];
        if ($at !== null) {
            $payload['php_clock'] = $at;
        }
        $creator = $this->spawn($payload);
        $issuer = $this->spawn($this->issuePayload($old));
        $this->release($creator);
        $this->awaitPoint($creator, 'before_commit');
        $this->release($issuer);
        $overlap = $this->blockedBy($issuer, $creator);
        $this->release($creator);
        $a = $this->finish($creator);
        $b = $this->finish($issuer);
        $this->assertTrue($a['ok']);
        $this->assertFalse($b['ok']);
        $this->assertSame('stale_preview', $b['kind']);
        $this->assertCount(0, $this->verifyInvoices([$s['elderly']->id]));
        $row = DB::table('service_enrollments')->where('id', $a['enrollment'])->sole();
        $this->assertSame('3100.00', $row->monthly_price);
        $this->assertNull($row->ended_at);
        if ($at !== null) {
            $this->assertTrue(DB::scalar("SELECT started_at=date_trunc('second',CAST(? AS timestamptz)) FROM service_enrollments WHERE id=?", [$at, $row->id]));
        } else {
            $this->assertTrue(DB::scalar("SELECT started_at>='2026-11-01T00:00:00+07'::timestamptz FROM service_enrollments WHERE id=?", [$row->id]));
        }
        $fresh = $this->command($s);
        $this->assertNotSame($old->confirmation->fingerprint, $fresh->confirmation->fingerprint);
        $outcome = $this->finalDecision($s, '2026-10-01', $decision, $amount, $s['family']->id,
            $changes !== [] ? 'multiple_enrollments' : null);
        $this->preserve($before, [...$this->billingTables(), 'service_enrollments']);
        $this->evidence('R09-create-first-'.($at ?? 'actual-clock'), [$a, $b, $outcome], $before, [...$overlap, 'php_clock_synthetic' => $at]);
    }

    public function test_issue_first_then_actual_enrollment_preserves_invoice_graph_and_rate(): void
    {
        $s = $this->source(['end' => '2026-11-01 00:00:00+07']);
        DB::table('service_classes')->where('id', $s['enrollment']->service_class_id)->update(['monthly_price' => '9000.00']);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->issuePayload($this->command($s), ['before_commit:1']));
        $creator = $this->spawn(['operation' => 'enroll', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id, 'class' => $s['enrollment']->service_class_id]);
        $this->release($issuer);
        $this->awaitPoint($issuer, 'before_commit');
        $this->release($creator);
        $overlap = $this->blockedBy($creator, $issuer);
        $this->release($issuer);
        $a = $this->finish($issuer);
        $b = $this->finish($creator);
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertSame('3100.00', $a['amount']);
        $this->assertSame('3100.00', $s['enrollment']->fresh()->monthly_price);
        $this->assertSame('9000.00', DB::table('service_enrollments')->where('id', $b['enrollment'])->value('monthly_price'));
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, [...$this->billingTables(), 'service_enrollments']);
        $this->evidence('R09-actual-issue-before-create', [$a, $b], $before, $overlap);
    }

    public function test_timestamp_input_is_rejected_and_no_ledger_is_implicitly_captured(): void
    {
        $s = $this->source(['no_service' => true, 'no_ledger' => true]);
        $before = $this->fingerprints();
        $request = new CreateEnrollmentRequest;
        $validation = Validator::make(['service_class_id' => DB::table('service_classes')->where('code', 'vip')->value('id'),
            'confirm_start' => true, 'started_at' => '2026-10-01', 'ended_at' => '2026-11-01', 'monthly_price' => '1.00'], $request->rules());
        $this->assertTrue($validation->fails());
        $this->assertSame(['monthly_price', 'started_at', 'ended_at'], array_keys($validation->errors()->toArray()));
        $worker = $this->spawn($this->transferPayload($s));
        $this->release($worker);
        $outcome = $this->finish($worker);
        $this->assertFalse($outcome['ok']);
        $this->assertSame('validation', $outcome['kind']);
        $this->assertSame(['operation'], $outcome['fields']);
        $this->assertSame(0, DB::table('financial_ownership_periods')->where('elderly_id', $s['elderly']->id)->count());
        $this->preserve($before, []);
        $this->evidence('R07-R09-no-backdate-input-no-implicit-capture', [$outcome], $before, ['validation_only' => true]);
    }

    public function test_no_service_issue_commits_before_actual_creation_without_phantom_invoice(): void
    {
        $s = $this->source(['no_service' => true]);
        $class = DB::table('service_classes')->where('code', 'vip')->sole();
        DB::table('service_classes')->where('id', $class->id)->update(['monthly_price' => '3100.00']);
        $old = $this->command($s);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->issuePayload($old, ['before_commit:1']));
        $creator = $this->spawn(['operation' => 'enroll', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id, 'class' => $class->id]);
        $this->release($issuer);
        $this->awaitPoint($issuer, 'before_commit');
        $this->release($creator);
        $overlap = $this->blockedBy($creator, $issuer);
        $this->release($issuer);
        $a = $this->finish($issuer);
        $b = $this->finish($creator);
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertSame('SKIP', $a['decision']);
        $this->assertNull($a['invoice']);
        $this->assertFailure('stale_preview', fn () => app(InvoiceIssuanceService::class)->issue($old));
        $fresh = $this->finalDecision($s, '2026-10-01', 'SKIP');
        $this->preserve($before, ['service_enrollments']);
        $this->evidence('R09-actual-no-service-issue-before-create', [$a, $b, $fresh], $before, $overlap);
    }

    public function test_controlled_closure_samples_php_clock_and_rejects_stale_full_month_preview(): void
    {
        $s = $this->source();
        $old = $this->command($s);
        $before = $this->fingerprints();
        $closer = $this->spawn(['operation' => 'close', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id,
            'enrollment' => $s['enrollment']->id, 'php_clock' => '2026-10-05 00:00:00.999999+07', 'pause' => ['before_commit:1']]);
        $issuer = $this->spawn($this->issuePayload($old));
        $this->release($closer);
        $this->awaitPoint($closer, 'before_commit');
        $this->release($issuer);
        $overlap = $this->blockedBy($issuer, $closer);
        $this->release($closer);
        $a = $this->finish($closer);
        $b = $this->finish($issuer);
        $this->assertTrue($a['ok']);
        $this->assertFalse($b['ok']);
        $this->assertSame('stale_preview', $b['kind']);
        $this->assertTrue(DB::scalar("SELECT ended_at='2026-10-05T00:00:00+07'::timestamptz FROM service_enrollments WHERE id=?", [$s['enrollment']->id]));
        $fresh = $this->finalDecision($s, '2026-10-01', 'ISSUE', '400.00', $s['family']->id);
        $this->preserve($before, [...$this->billingTables(), 'service_enrollments']);
        $this->evidence('R09-controlled-closure', [$a, $b, $fresh], $before, $overlap);
    }

    private function billingTables(): array
    {
        return ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions'];
    }

    private function transferPayload(array $s): array
    {
        return ['operation' => 'transfer', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id,
            'family' => $s['next']->id, 'expected' => $s['family']->id];
    }

    private function monthCommand(array $s, string $month): InvoiceIssueCommand
    {
        $preview = app(InvoiceIssuanceService::class)->preview($s['admin']->id, $s['elderly']->id, $month);

        return new InvoiceIssueCommand($s['admin']->id, $s['elderly']->id, $month, $this->billingId(), $preview->confirmation);
    }

    private function finalDecision(array $s, string $month, string $decision, ?string $amount = null, ?string $owner = null, ?string $reason = null): array
    {
        $service = app(InvoiceIssuanceService::class);
        $preview = $service->preview($s['admin']->id, $s['elderly']->id, $month);
        $this->assertSame($decision, $preview->eligibility->decision->value);
        $reason ??= match ($decision) {
            'ISSUE' => 'eligible', 'SKIP' => 'no_chargeable_service', default => 'mixed_owner_calendar_month'
        };
        $this->assertSame($reason, $preview->eligibility->reason->value);
        $command = $this->monthCommand($s, $month);
        $this->assertSame($preview->confirmation->fingerprint, $command->confirmation->fingerprint);
        $this->assertTrue(DB::scalar("SELECT clock_timestamp()>=(CAST(? AS date)+INTERVAL '1 month')::timestamp AT TIME ZONE 'Asia/Jakarta'", [$month]));
        DB::select('SELECT public.billing_validate_ownership(?)', [$s['elderly']->id]);
        $result = $service->issue($command);
        $this->assertSame($decision, $result->decision->value);
        $this->assertSame($reason, $result->reason->value);
        $this->assertSame($amount, $result->amount);
        $roots = $this->verifyInvoices([$s['elderly']->id]);
        $this->assertCount($decision === 'ISSUE' ? 1 : 0, $roots);
        if ($decision === 'ISSUE') {
            $root = DB::table('invoices')->where('id', $result->invoiceId)->sole();
            $this->assertSame($owner, $root->bill_to_family_id);
            $this->assertSame($preview->eligibility->ownershipPeriodId, $root->financial_ownership_period_id);
        } else {
            $this->assertNull($result->invoiceId);
            $this->assertNull($preview->eligibility->calculation);
        }

        return ['decision' => $decision, 'reason' => $reason, 'amount' => $amount, 'owner' => $decision === 'ISSUE' ? $owner : null,
            'elderly_id' => $s['elderly']->id,
            'source_fingerprint' => $command->confirmation->fingerprint, 'month' => $month];
    }
}
