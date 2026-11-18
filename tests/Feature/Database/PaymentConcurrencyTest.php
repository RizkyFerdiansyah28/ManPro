<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PaymentCoreTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('payment-core')]
class PaymentConcurrencyTest extends PaymentCoreTestCase
{
    public static function races(): array
    {
        return [['submit-submit'], ['same-key-submit'], ['submit-transfer'], ['transfer-submit'],
            ['submit-revision'], ['revision-submit'], ['verify-verify'], ['same-key-verify'],
            ['verify-reject'], ['reject-verify'], ['verify-revision'], ['verify-cancel'],
            ['deactivate-submit'], ['deactivate-verify']];
    }

    #[DataProvider('races')]
    public function test_real_independent_lock_waits_preserve_one_valid_serial_outcome(string $case): void
    {
        $s = $this->paymentSource();
        $isReview = str_contains($case, 'verify') || $case === 'reject-verify';
        $command = $this->submitCommand($s);
        if ($isReview) {
            [,$p] = $this->pending($s);
            $command = $this->reviewCommand($s, $p->paymentId, $case === 'reject-verify' ? 'reject' : 'verify');
        }
        $one = ['operation' => $command->data['operation'], 'command' => $command->data, 'resident' => $s['elderly']->id];
        $two = $one;
        $two['command']['request_id'] = (string) Str::uuid();
        if (str_starts_with($case, 'same-key')) {
            $two = $one;
        }
        if (in_array($case, ['verify-reject', 'reject-verify'], true)) {
            $op = $case === 'verify-reject' ? 'reject' : 'verify';
            $two['operation'] = $op;
            $two['command'] = $this->reviewCommand($s, $p->paymentId, $op)->data;
        }
        $mutator = null;
        if (str_contains($case, 'transfer')) {
            $mutator = ['operation' => 'transfer', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id,
                'new_family' => $s['next']->id, 'old_family' => $s['family']->id];
        } elseif (str_contains($case, 'revision') || str_contains($case, 'cancel')) {
            $mutator = ['operation' => str_contains($case, 'cancel') ? 'fixture_cancel' : 'fixture_revise',
                'actor' => $s['admin']->id, 'invoice' => $s['issue']->invoiceId, 'resident' => $s['elderly']->id];
        } elseif (str_starts_with($case, 'deactivate')) {
            $mutator = ['operation' => 'fixture_deactivate', 'actor' => $isReview ? $s['admin']->id : $s['family']->id];
        }
        if ($mutator) {
            if (str_starts_with($case, 'transfer-') || str_starts_with($case, 'revision-') || str_starts_with($case, 'deactivate-')) {
                $one = $mutator;
            } else {
                $two = $mutator;
            }
        }
        $one['pause'] = ['before_commit:1'];
        $before = $this->fingerprints();
        $a = $this->spawn($one);
        $b = $this->spawn($two);
        $this->release($a);
        $this->awaitPoint($a, 'before_commit');
        $this->release($b);
        $overlap = $this->blockedBy($b, $a);
        $this->release($a);
        $first = $this->finish($a);
        $second = $this->finish($b);
        $this->assertSame('success', $first['kind']);
        $both = in_array($case, ['same-key-submit', 'same-key-verify', 'submit-transfer'], true);
        if ($both) {
            $this->assertSame('success', $second['kind']);
            if (str_starts_with($case, 'same-key')) {
                $this->assertTrue($second['replayed']);
            }
        } else {
            $expected = match ($case) {
                'submit-submit' => ['pending_payment_exists'],
                'transfer-submit' => ['unauthorized', 'stale_owner_context'],
                'revision-submit' => ['invoice_not_eligible'],
                'verify-verify','verify-reject' => ['invoice_not_eligible'],
                'reject-verify' => ['stale_payment_state'],
                'deactivate-submit','deactivate-verify' => ['unauthorized'],
                default => ['integrity'],
            };
            $this->assertContains($second['kind'], $expected);
            if ($second['kind'] === 'integrity') {
                $this->assertSame('23514', $second['sqlstate']);
                $this->assertContains($second['rule'], ['billing_revision_payment_conflict', 'billing_revision_parent_state', 'billing_invoice_terminal_immutable']);
            }
        }
        $root = DB::table('invoices')->where('id', $s['issue']->invoiceId)->first();
        $this->assertSame($s['family']->id, $root->bill_to_family_id);
        $this->assertSame($s['issue']->invoiceId, $root->id);
        $rows = DB::table('payments')->where('invoice_id', $root->id)->get();
        $this->assertLessThanOrEqual(1, $rows->where('status', 'pending')->count());
        $this->assertLessThanOrEqual(1, $rows->where('status', 'verified')->count());
        $this->assertCount(in_array($case, ['transfer-submit', 'revision-submit', 'deactivate-submit'], true) ? 0 : 1, $rows);
        if (in_array($case, ['verify-verify', 'same-key-verify', 'verify-reject', 'verify-revision', 'verify-cancel'], true)) {
            $this->assertSame('paid', $root->status);
            $this->assertSame($root->paid_at, $rows->first()->verified_at);
        }
        if ($case === 'reject-verify') {
            $this->assertSame('rejected', $rows->first()->status);
        }
        DB::select('SELECT public.billing_validate_invoice(?)', [$root->id]);
        DB::select('SELECT public.billing_validate_ownership(?)', [$root->elderly_id]);
        $allowed = ['invoices', 'payments', 'invoice_revisions', 'billing_requests', 'billing_actions'];
        if (str_contains($case, 'transfer')) {
            $allowed = [...$allowed, 'elderlies', 'financial_ownership_periods'];
        }
        if (str_starts_with($case, 'deactivate')) {
            $allowed[] = 'users';
        }
        $this->unchanged($before, $allowed);
        $this->record($case, [$first, $second], $overlap, $root->id);
    }

    public function test_uncommitted_submission_is_unresolved_without_wait_or_first_mutation_then_replays(): void
    {
        $s = $this->paymentSource();
        $command = $this->submitCommand($s);
        $a = $this->spawn(['operation' => 'submit', 'command' => $command->data, 'pause' => ['before_commit:1']]);
        $b = $this->spawn(['operation' => 'reconcile', 'command' => $command->data]);
        $before = $this->fingerprints();
        $this->release($a);
        $this->awaitPoint($a, 'before_commit');
        $this->release($b);
        $absent = $this->finish($b);
        $this->assertSame('outcome_unresolved', $absent['kind']);
        $this->assertCount(0, array_filter($this->events($b), fn ($e) => str_starts_with($e['point'], 'lock_')));
        $this->unchanged($before); // Holder has not committed.
        $this->release($a);
        $committed = $this->finish($a);
        $this->assertSame('success', $committed['kind']);
        $after = $this->fingerprints();
        $c = $this->spawn(['operation' => 'reconcile', 'command' => $command->data]);
        $this->release($c);
        $replay = $this->finish($c);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($committed['payment'], $replay['payment']);
        $this->unchanged($after);
        $this->record('uncommitted-reconcile', [$absent, $committed, $replay], ['holder_paused_before_commit' => true], $s['issue']->invoiceId);
    }

    public function test_lock_timeout_is_not_a_success_and_only_the_holder_commits(): void
    {
        $s = $this->paymentSource();
        $first = $this->submitCommand($s);
        $second = $this->submitCommand($s);
        $a = $this->spawn(['operation' => 'submit', 'command' => $first->data, 'pause' => ['before_commit:1']]);
        $b = $this->spawn(['operation' => 'submit', 'command' => $second->data]);
        $this->release($a);
        $this->awaitPoint($a, 'before_commit');
        $this->release($b);
        $overlap = $this->blockedBy($b, $a);
        $loser = $this->finish($b);
        $this->assertSame('lock_timeout', $loser['kind']);
        $this->assertSame('55P03', $loser['sqlstate']);
        $this->release($a);
        $winner = $this->finish($a);
        $this->assertSame('success', $winner['kind']);
        $this->assertSame(1, DB::table('payments')->where('invoice_id', $s['issue']->invoiceId)->count());
        $this->record('lock-timeout', [$winner, $loser], $overlap, $s['issue']->invoiceId);
    }

    private function record(string $case, array $outcomes, array $overlap, string $invoice): void
    {
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe($this->app, DB::connection());
        $root = DB::table('invoices')->where('id', $invoice)->first();
        $events = [];
        foreach (array_keys($this->workers) as $index) {
            $events[$index] = $this->events($index);
        }
        $record = ['scenario' => $case, 'database' => $guard->identity['database'], 'outcomes' => $outcomes, 'overlap' => $overlap, 'events' => $events,
            'final' => ['status' => $root->status, 'owner' => $root->bill_to_family_id,
                'payments' => DB::table('payments')->where('invoice_id', $invoice)->selectRaw('status,count(*)::int as count')->groupBy('status')->get()->all(),
                'revisions' => DB::table('invoice_revisions')->where('invoice_id', $invoice)->count(),
                'requests' => DB::table('billing_requests')->where('invoice_id', $invoice)->count(),
                'actions' => DB::table('billing_actions')->where('invoice_id', $invoice)->count()]];
        $path = base_path('docs/report/SILANSIA_P9_4B_TEST_EVIDENCE_'.substr($guard->identity['database'], -16).'.jsonl');
        $this->assertNotFalse(file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX));
    }
}
