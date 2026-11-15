<?php

namespace Tests\Feature\Database;

use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceIssueConfirmation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceConcurrencyTestCase;

#[Group('invoice-concurrency')]
class InvoiceIssuanceConcurrencyTest extends InvoiceConcurrencyTestCase
{
    public static function requestRaces(): array
    {
        return array_map(fn ($r) => [$r], ['R01', 'R01-zero', 'R02', 'R03', 'R04', 'R05', 'R06', 'R15']);
    }

    #[DataProvider('requestRaces')]
    public function test_concurrent_request_namespaces_root_absence_and_independence(string $race): void
    {
        $s = $this->source(['price' => $race === 'R01-zero' ? '0.00' : '3100.00']);
        $one = $this->command($s);
        $two = $one;
        $targets = [$s['elderly']->id];
        if ($race === 'R02') {
            $two = new InvoiceIssueCommand($one->actorId, $one->elderlyId, $one->billingMonth, $one->requestId, new InvoiceIssueConfirmation(str_repeat('a', 64)));
        } elseif ($race === 'R03') {
            $two = new InvoiceIssueCommand($one->actorId, $one->elderlyId, $one->billingMonth, $this->billingId(), $one->confirmation);
        } elseif (in_array($race, ['R04', 'R15'], true)) {
            $otherAdmin = $this->account();
            $two = new InvoiceIssueCommand($otherAdmin->id, $one->elderlyId, $one->billingMonth, $this->billingId(), $one->confirmation);
        } elseif (in_array($race, ['R05', 'R06'], true)) {
            $other = $this->source();
            $targets[] = $other['elderly']->id;
            $fresh = $this->command($other);
            $two = new InvoiceIssueCommand($race === 'R05' ? $one->actorId : $fresh->actorId, $fresh->elderlyId, $fresh->billingMonth, $fresh->requestId, $fresh->confirmation);
        }
        $before = $this->fingerprints();
        $first = $this->spawn($this->issuePayload($one, ['preread:1', 'lock_elderlies:1', 'before_commit:1']));
        $second = $this->spawn($this->issuePayload($two, ['preread:1', 'before_commit:1']));
        $this->release($first);
        $this->release($second);
        $this->awaitPoint($first, 'preread');
        $this->awaitPoint($second, 'preread');
        foreach ([$first, $second] as $worker) {
            $this->assertTrue($this->awaitPoint($worker, 'observed_root')['absent']);
        }
        $this->release($first);
        $this->awaitPoint($first, 'lock_elderlies');
        $this->release($second);
        if ($race === 'R06') {
            $this->awaitPoint($second, 'before_commit');
            $this->release($first);
            $this->awaitPoint($first, 'before_commit');
            $pids = [$this->awaitPoint($first, 'begin')['backend_pid'], $this->awaitPoint($second, 'begin')['backend_pid']];
            $this->assertSame(2, DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('state', 'idle in transaction')->whereNotNull('xact_start')->count());
            $overlap = ['independent_open_transactions' => $pids];
            $this->release($second);
        } else {
            $overlap = $this->blockedBy($second, $first);
            $this->release($first);
            $this->awaitPoint($first, 'before_commit');
        }
        $this->release($first);
        $a = $this->finish($first);
        if ($race === 'R06') {
            $b = $this->finish($second);
        } elseif (in_array($race, ['R01', 'R01-zero', 'R05'], true)) {
            $this->awaitPoint($second, 'before_commit');
            $this->release($second);
            $b = $this->finish($second);
        } else {
            $b = $this->finish($second);
        }
        $this->assertTrue($a['ok']);
        $this->assertSame('ISSUE', $a['decision']);
        if (in_array($race, ['R01', 'R01-zero'], true)) {
            $this->assertTrue($b['ok']);
            $this->assertTrue($b['replayed']);
            $this->assertSame($a['invoice'], $b['invoice']);
            $this->assertSame($a['revision'], $b['revision']);
        } elseif (in_array($race, ['R05', 'R06'], true)) {
            $this->assertTrue($b['ok']);
            $this->assertSame('ISSUE', $b['decision']);
            $this->assertNotSame($a['invoice'], $b['invoice']);
            $this->assertSame($two->elderlyId, DB::table('invoices')->where('id', $b['invoice'])->value('elderly_id'));
        } else {
            $this->assertFalse($b['ok']);
            $this->assertSame($race === 'R02' ? 'idempotency_conflict' : 'existing_root_conflict', $b['kind']);
            $this->assertNull($b['sqlstate']);
            $this->assertArrayNotHasKey('invoice', $b);
        }
        $this->assertCount(count($targets), $this->verifyInvoices($targets));
        $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions']);
        $this->evidence($race, [$a, $b], $before, $overlap);
    }

    public static function sourceRaces(): array
    {
        return [['R07-transfer-first'], ['R07-issue-first'], ['R07-mixed-history'], ['R07-boundary-history'],
            ['R08-close-first'], ['R08-issue-first'], ['R09-enroll-first'], ['R09-multiple-history'],
            ['R10-master'], ['R11-role'], ['R12-inactive'], ['R13-resident'], ['R14-role-replay'], ['R14-inactive-replay']];
    }

    #[DataProvider('sourceRaces')]
    public function test_real_supported_source_operations_overlap_with_issue_and_replay(string $race): void
    {
        $changes = match ($race) {
            'R09-enroll-first' => ['no_service' => true],
            'R13-resident' => ['end' => '2026-11-01 00:00:00+07'],
            'R07-mixed-history' => ['end' => '2026-10-05 00:00:00+07'],
            default => [],
        };
        $s = $this->source($changes);
        if (in_array($race, ['R07-mixed-history', 'R07-boundary-history'], true)) {
            $this->sourceTransfer($s, $race === 'R07-mixed-history' ? '2026-10-20 00:00:00.123456+07' : '2026-10-01 00:00:00+07');
        }
        if ($race === 'R09-multiple-history') {
            // Historis sintetis, bukan backdate dari workflow operasional.
            DB::table('service_enrollments')->where('id', $s['enrollment']->id)->update(['ended_at' => '2026-10-10 00:00:00+07']);
            DB::table('service_enrollments')->insert(['id' => $this->billingId(), 'elderly_id' => $s['elderly']->id,
                'service_class_id' => $s['enrollment']->service_class_id, 'monthly_price' => '3100.00',
                'started_at' => '2026-10-10 00:00:00+07', 'ended_at' => '2026-11-01 00:00:00+07']);
        }
        $command = $this->command($s);
        if (str_contains($race, 'replay')) {
            app(InvoiceIssuanceService::class)->issue($command);
        }
        $actor = $s['admin']->id;
        $mutation = ['operation' => 'transfer', 'actor' => $actor, 'resident' => $s['elderly']->id,
            'family' => in_array($race, ['R07-mixed-history', 'R07-boundary-history'], true) ? $s['family']->id : $s['next']->id,
            'expected' => DB::table('elderlies')->where('id', $s['elderly']->id)->value('family_id')];
        $allowed = ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'financial_ownership_periods', 'elderlies'];
        if (str_starts_with($race, 'R08')) {
            $mutation = ['operation' => 'close', 'actor' => $actor, 'resident' => $s['elderly']->id, 'enrollment' => $s['enrollment']->id];
            $allowed = ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'service_enrollments'];
        } elseif (str_starts_with($race, 'R09')) {
            $class = DB::table('service_classes')->where('code', 'vip')->sole();
            DB::table('service_classes')->where('id', $class->id)->update(['monthly_price' => '3100.00']);
            $mutation = ['operation' => 'enroll', 'actor' => $actor, 'resident' => $s['elderly']->id, 'class' => $class->id];
            $allowed = ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'service_enrollments'];
        } elseif ($race === 'R10-master') {
            $mutation = ['operation' => 'master', 'actor' => $actor, 'class' => $s['enrollment']->service_class_id];
            $allowed = ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'service_classes'];
        } elseif ($race === 'R13-resident') {
            $mutation = ['operation' => 'resident_inactive', 'actor' => $actor, 'resident' => $s['elderly']->id];
            $allowed = ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'elderlies'];
        } elseif (str_starts_with($race, 'R11') || str_starts_with($race, 'R12') || str_starts_with($race, 'R14')) {
            $mutation = ['operation' => str_contains($race, 'role') ? 'actor_role' : 'actor_inactive', 'target' => $actor];
            $allowed = ['users'];
        }
        $before = $this->fingerprints();
        $issueFirst = in_array($race, ['R07-issue-first', 'R08-issue-first'], true);
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
        $issue = $issueFirst ? $a : $b;
        if (str_starts_with($race, 'R11') || str_starts_with($race, 'R12') || str_starts_with($race, 'R14')) {
            $this->assertFalse($issue['ok']);
            $this->assertSame('unauthorized', $issue['kind']);
            $this->assertArrayNotHasKey('amount', $issue);
        } elseif ($issueFirst || in_array($race, ['R10-master', 'R13-resident'], true)) {
            $this->assertTrue($issue['ok']);
            $this->assertSame('ISSUE', $issue['decision']);
            $this->assertSame('3100.00', $issue['amount']);
        } else {
            $this->assertFalse($issue['ok']);
            $this->assertSame('stale_preview', $issue['kind']);
        }
        $expectedRoots = $issueFirst || in_array($race, ['R10-master', 'R13-resident'], true) || str_starts_with($race, 'R14') ? 1 : 0;
        $this->assertCount($expectedRoots, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, $allowed);
        if (str_starts_with($race, 'R07')) {
            DB::select('SELECT public.billing_validate_ownership(?)', [$s['elderly']->id]);
            $this->assertSame($mutation['family'], DB::table('elderlies')->where('id', $s['elderly']->id)->value('family_id'));
        }
        if ($race === 'R13-resident') {
            $this->assertFalse($s['elderly']->fresh()->is_active);
        }
        if (in_array($race, ['R09-enroll-first', 'R09-multiple-history', 'R07-mixed-history', 'R07-boundary-history', 'R07-transfer-first', 'R08-close-first'], true)) {
            $fresh = app(InvoiceIssuanceService::class)->preview($actor, $s['elderly']->id, '2026-10-01');
            $this->assertSame(match ($race) {
                'R09-enroll-first' => 'SKIP', 'R09-multiple-history', 'R07-mixed-history' => 'HOLD', default => 'ISSUE'
            }, $fresh->eligibility->decision->value);
        }
        $this->evidence($race, [$a, $b], $before, $overlap);
    }

    public static function staleSets(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('staleSets')]
    public function test_stale_user_set_restarts_whole_transaction_without_late_lock(bool $exhaust): void
    {
        $s = $this->source();
        $third = $this->account('keluarga');
        $otherAdmin = $this->account();
        $command = $this->command($s);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->issuePayload($command, $exhaust ? ['preread:1', 'preread:2'] : ['preread:1']));
        $this->release($issuer);
        $this->awaitPoint($issuer, 'preread');
        $first = $this->spawn(['operation' => 'transfer', 'actor' => $otherAdmin->id, 'resident' => $s['elderly']->id,
            'family' => $s['next']->id, 'expected' => $s['family']->id]);
        $this->release($first);
        $a = $this->finish($first);
        $this->assertTrue($a['ok']);
        $this->release($issuer);
        $outcomes = [$a];
        if ($exhaust) {
            $this->awaitPoint($issuer, 'preread', 2);
            $second = $this->spawn(['operation' => 'transfer', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id,
                'family' => $third->id, 'expected' => $s['next']->id]);
            $this->release($second);
            $outcomes[] = $this->finish($second);
            $this->release($issuer);
        }
        $result = $this->finish($issuer);
        $this->assertFalse($result['ok']);
        $this->assertSame($exhaust ? 'transient_retry_exhausted' : 'stale_preview', $result['kind']);
        $this->assertSame(2, count(array_filter($this->events($issuer), fn ($e) => $e['point'] === 'begin')));
        $this->assertGreaterThanOrEqual(1, count(array_filter($this->events($issuer), fn ($e) => $e['point'] === 'rollback')));
        $this->assertCount(0, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, ['elderlies', 'financial_ownership_periods', 'billing_actions']);
        $this->evidence('R16-'.($exhaust ? 'exhaustion' : 'restart'), [...$outcomes, $result], $before, ['pipe_preread_overlapped_transfer' => true]);
    }
}
