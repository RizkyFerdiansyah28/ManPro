<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceConcurrencyTestCase;

#[Group('invoice-concurrency')]
class InvoiceIssuanceFailureConcurrencyTest extends InvoiceConcurrencyTestCase
{
    public static function retryChanges(): array
    {
        return [['actor_inactive'], ['close']];
    }

    #[DataProvider('retryChanges')]
    public function test_retry_reauthorizes_and_revalidates_committed_source_changes(string $operation): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $sequence = $this->databaseFault('root', '40P01', 1);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->issuePayload($command, ['preread:2']));
        $this->release($issuer);
        $this->awaitPoint($issuer, 'preread', 2);
        $this->assertSame(1, DB::scalar("SELECT last_value::int FROM public.{$sequence}"));
        $mutation = $operation === 'close'
            ? ['operation' => 'close', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id, 'enrollment' => $s['enrollment']->id]
            : ['operation' => 'actor_inactive', 'target' => $s['admin']->id];
        $mutator = $this->spawn($mutation);
        $this->release($mutator);
        $changed = $this->finish($mutator);
        $this->assertTrue($changed['ok']);
        $this->release($issuer);
        $result = $this->finish($issuer);
        $this->assertFalse($result['ok']);
        $this->assertSame($operation === 'close' ? 'stale_preview' : 'unauthorized', $result['kind']);
        $this->assertCount(2, array_filter($this->events($issuer), fn ($e) => $e['point'] === 'lock_users'));
        $this->assertCount(0, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, [$operation === 'close' ? 'service_enrollments' : 'users']);
        $this->evidence('F-retry-revalidate-'.$operation, [$changed, $result], $before, ['between_attempts_barrier' => true, 'injected_sqlstate' => '40P01']);
    }

    public static function failures(): array
    {
        return [['40P01', 1], ['40P01', 2], ['40001', 1], ['40001', 2], ['statement', 1], ['projection', 1],
            ['root', 1], ['revision', 1], ['request', 1], ['action', 1], ['zero', 1], ['disconnect_root', 1], ['disconnect_commit', 1]];
    }

    #[DataProvider('failures')]
    public function test_failure_retry_and_deferred_commit_never_leave_partial_graph(string $case, int $limit): void
    {
        $s = $this->source(['price' => $case === 'zero' ? '0.00' : '3100.00']);
        $command = $this->command($s);
        $sequence = null;
        if (in_array($case, ['40P01', '40001'], true)) {
            $sequence = $this->databaseFault('root', $case, $limit);
        } elseif (in_array($case, ['statement', 'root', 'revision', 'request', 'action', 'zero'], true)) {
            $this->databaseFault($case);
        }
        $pause = match ($case) {
            'disconnect_root' => ['root_inserted:1'], 'disconnect_commit' => ['before_commit:1'], default => [],
        };
        $before = $this->fingerprints();
        $worker = $this->spawn($this->issuePayload($command, $pause, in_array($case, ['statement', 'projection'], true) ? $case : ''));
        $this->release($worker);
        if ($pause !== []) {
            $point = $case === 'disconnect_root' ? 'root_inserted' : 'before_commit';
            $this->awaitPoint($worker, $point);
            $this->disconnectOwnedWorker($worker, $point);
            $this->release($worker);
        }
        $result = $this->finish($worker);
        $success = $sequence && $limit === 1;
        $this->assertSame((bool) $success, $result['ok']);
        if ($success) {
            $this->assertSame('ISSUE', $result['decision']);
            $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
            $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions']);
        } else {
            $this->assertSame(match ($case) {
                '40P01', '40001' => 'transient_retry_exhausted', 'statement' => 'statement_timeout',
                'disconnect_root' => 'infrastructure_failure', 'disconnect_commit' => 'unknown_commit_outcome', default => 'integrity_violation',
            }, $result['kind']);
            if (in_array($case, ['40P01', '40001', 'statement'], true)) {
                $this->assertSame($case === 'statement' ? '57014' : $case, $result['sqlstate']);
            } elseif (! str_starts_with($case, 'disconnect')) {
                $this->assertSame('23514', $result['sqlstate']);
                $this->assertSame($case === 'projection' ? 'billing_invoice_projection' : 'test_issue_fault', $result['rule']);
            }
            $this->assertEquals($before, $this->fingerprints());
            $this->assertCount(0, $this->verifyInvoices([$s['elderly']->id]));
            $this->assertSame(0, DB::table('billing_requests')->where('actor_id', $command->actorId)->where('request_id', $command->requestId)->count());
        }
        if ($sequence) {
            $this->assertSame(2, DB::scalar("SELECT last_value::int FROM public.{$sequence}"));
            $begins = array_filter($this->events($worker), fn ($e) => $e['point'] === 'begin');
            $this->assertCount(2, $begins);
            $userLocks = array_filter($this->events($worker), fn ($e) => $e['point'] === 'lock_users');
            $this->assertCount(2, $userLocks);
        }
        $this->evidence('F-'.$case.'-'.$limit, [$result], $before,
            ['independent_worker' => true, 'injected_sqlstate' => $sequence !== null, 'backend_terminated' => str_starts_with($case, 'disconnect')]);
    }

    public function test_lock_timeout_is_not_success_and_only_holder_commits(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $before = $this->fingerprints();
        $holder = $this->spawn($this->issuePayload($command, ['before_commit:1']));
        $waiter = $this->spawn($this->issuePayload($command));
        $this->release($holder);
        $this->awaitPoint($holder, 'before_commit');
        $this->release($waiter);
        $overlap = $this->blockedBy($waiter, $holder);
        $failed = $this->finish($waiter);
        $this->assertFalse($failed['ok']);
        $this->assertSame('lock_timeout', $failed['kind']);
        $this->assertSame('55P03', $failed['sqlstate']);
        $this->release($holder);
        $committed = $this->finish($holder);
        $this->assertTrue($committed['ok']);
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions']);
        $this->evidence('F-real-lock-timeout', [$committed, $failed], $before, $overlap);
    }

    public function test_lost_acknowledgement_and_concurrent_replays_share_one_durable_outcome(): void
    {
        $s = $this->source();
        $command = $this->command($s);
        $before = $this->fingerprints();
        $initial = $this->spawn($this->issuePayload($command, ['committed:1'], 'ack_loss'));
        $this->release($initial);
        $this->awaitPoint($initial, 'committed');
        $committedBefore = $this->fingerprints();
        $second = $this->spawn($this->issuePayload($command, ['before_commit:1']));
        $third = $this->spawn($this->issuePayload($command));
        $this->release($second);
        $this->awaitPoint($second, 'before_commit');
        $this->release($third);
        $overlap = [$this->blockedBy($third, $second)];
        $this->release($initial);
        $overlap[] = $this->blockedBy($initial, $second, 2);
        $this->release($second);
        $outcomes = [$this->finish($initial), $this->finish($second), $this->finish($third)];
        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['ok'], json_encode(['outcomes' => $outcomes, 'events' => array_map(fn ($i) => $this->events($i), [$initial, $second, $third])], JSON_THROW_ON_ERROR));
            $this->assertTrue($outcome['replayed']);
            $this->assertSame($outcomes[0]['invoice'], $outcome['invoice']);
            $this->assertSame($outcomes[0]['revision'], $outcome['revision']);
        }
        $this->assertEquals($committedBefore, $this->fingerprints());
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->evidence('F-ack-loss-concurrent-replay', $outcomes, $before, $overlap);
    }
}
