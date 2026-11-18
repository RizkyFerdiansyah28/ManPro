<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\PaymentHttpTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('payment-http')]
class PaymentHttpConcurrencyTest extends PaymentHttpTestCase
{
    protected function spawn(array $payload): int
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        $input = new InputStream;
        $process = new Process([PHP_BINARY, 'tests/Support/payment-http-worker.php'], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'PAYMENT_CORE_ENABLED' => 'true',
                'INVOICE_ISSUANCE_ENABLED' => 'true', 'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true', 'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-test-only-approval'], $input);
        $input->write(json_encode(['case' => $this->caseId, 'session_id' => app('session')->driver()->getId(), ...$payload], JSON_THROW_ON_ERROR)."\n");
        $process->setTimeout(55);
        $process->start();
        $index = count($this->workers);
        $this->workers[] = [$process, $input, $process->getPid(), $payload];
        $ready = $this->awaitPoint($index, 'ready');
        $this->assertSame(PostgresTestGuard::fromEnvironment()->identity['database'], $ready['database']);
        $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $ready['backend_pid']);

        return $index;
    }

    public static function races(): array
    {
        return [['submit-submit'], ['same-context-submit'], ['verify-verify'], ['same-context-verify'], ['verify-reject'], ['submit-transfer']];
    }

    #[DataProvider('races')]
    public function test_http_commands_overlap_on_independent_pids_and_preserve_serial_outcome(string $case): void
    {
        $s = $this->httpSource();
        $isReview = str_contains($case, 'verify');
        if ($isReview) {
            $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
            $this->actingAs($s['admin']);
            $url = $this->reviewUrl($s, $payment);
            $one = ['operation' => 'http', 'actor' => $s['admin']->id, 'path' => $url.'/verify',
                'body' => ['command_context' => $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context'), 'confirm' => true, 'bank_receipt_checked' => true]];
            $two = $one;
            if ($case === 'verify-reject') {
                $two['path'] = $url.'/reject';
                $two['body'] = ['command_context' => $this->getJson($url.'/review-context?operation=reject')->assertOk()->json('command_context'), 'confirm' => true, 'reason_code' => 'not_received', 'public_reason' => 'Synthetic reason.'];
            } else {
                $two['body']['command_context'] = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
            }
        } else {
            $this->actingAs($s['family']);
            $one = ['operation' => 'http', 'actor' => $s['family']->id, 'path' => $s['invoice_url'].'/payments', 'upload' => $this->upload()->getPathname(),
                'body' => ['command_context' => $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context'), 'confirm' => '1']];
            $two = $one;
            $two['body']['command_context'] = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
            if ($case === 'submit-transfer') {
                $two = ['operation' => 'transfer', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id, 'new_family' => $s['next']->id, 'old_family' => $s['family']->id];
            }
        }
        if (str_starts_with($case, 'same-context')) {
            $two = $one;
        }
        $one['pause'] = ['before_commit:1'];
        $a = $this->spawn($one);
        $b = $this->spawn($two);
        $before = $this->fingerprints();
        $this->release($a);
        $this->awaitPoint($a, 'before_commit');
        $this->release($b);
        $overlap = $this->blockedBy($b, $a);
        $this->release($a);
        $first = $this->finish($a);
        $second = $this->finish($b);
        $this->assertSame('success', $first['kind']);
        $this->assertTrue($first['csrf_enforced']);
        $this->assertTrue($first['no_store']);
        $this->assertSame(match ($case) {
            'submit-transfer', 'same-context-submit', 'same-context-verify' => 'success', 'submit-submit' => 'pending_payment_exists', default => 'invoice_not_eligible'
        }, $second['kind']);
        if (str_starts_with($case, 'same-context')) {
            $this->assertTrue($second['replayed']);
            $this->assertSame($first['payment'], $second['payment']);
        }
        $this->assertSame(1, DB::table('payments')->where('invoice_id', $s['issue']->invoiceId)->count());
        $this->assertSame($s['family']->id, DB::table('invoices')->where('id', $s['issue']->invoiceId)->value('bill_to_family_id'));
        DB::select('SELECT public.billing_validate_invoice(?)', [$s['issue']->invoiceId]);
        $this->unchanged($before, ['invoices', 'payments', 'billing_requests', 'billing_actions', 'sessions', ...($case === 'submit-transfer' ? ['elderlies', 'financial_ownership_periods'] : [])]);
        $this->record('http-'.$case, [$first, $second], $overlap, $s['issue']->invoiceId);
    }

    public function test_uncommitted_and_lost_acknowledgment_use_original_context_without_browser_file(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $token = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
        $fresh = $this->getJson('/billing/payment-commands/recovery-context')->assertOk()->json('recovery_context');
        $a = $this->spawn(['operation' => 'http', 'actor' => $s['family']->id, 'path' => $s['invoice_url'].'/payments',
            'upload' => $this->upload()->getPathname(), 'body' => ['command_context' => $token, 'confirm' => '1'], 'pause' => ['before_commit:1'], 'fault' => 'ack_loss']);
        $b = $this->spawn(['operation' => 'http', 'actor' => $s['family']->id, 'path' => '/billing/payment-commands/reconcile',
            'body' => ['command_context' => $token, 'recovery_context' => $fresh]]);
        $this->release($a);
        $this->awaitPoint($a, 'before_commit');
        $before = $this->fingerprints();
        $this->release($b);
        $absent = $this->finish($b);
        $this->assertSame('outcome_unresolved', $absent['kind']);
        $this->assertCount(0, array_filter($this->events($b), fn ($e) => str_starts_with($e['point'], 'lock_')));
        $this->unchanged($before, ['sessions']);
        $this->release($a);
        $lost = $this->finish($a);
        $this->assertSame('unknown_commit_outcome', $lost['kind']);
        $after = $this->fingerprints();
        $this->reconcileHttp($token)->assertOk()->assertJsonPath('command_fact', 'submission_recorded');
        $this->unchanged($after, ['sessions']);
        $this->assertSame(1, DB::table('payments')->where('invoice_id', $s['issue']->invoiceId)->count());
        $this->record('http-unknown-commit', [$absent, $lost], ['independent_pids' => true, 'uncommitted_nonblocking' => true], $s['issue']->invoiceId);
    }

    private function record(string $scenario, array $outcomes, array $overlap, string $invoice): void
    {
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe(app(), DB::connection());
        $events = [];
        foreach (array_keys($this->workers) as $index) {
            $events[] = $this->events($index);
        }
        $record = ['scenario' => $scenario, 'database' => $guard->identity['database'], 'outcomes' => $outcomes,
            'overlap' => $overlap, 'events' => $events, 'final' => [
                'invoice_status' => DB::table('invoices')->where('id', $invoice)->value('status'),
                'payments' => DB::table('payments')->where('invoice_id', $invoice)->count(),
                'revisions' => DB::table('invoice_revisions')->where('invoice_id', $invoice)->count(),
                'requests' => DB::table('billing_requests')->where('invoice_id', $invoice)->count(),
                'actions' => DB::table('billing_actions')->where('invoice_id', $invoice)->count(),
            ]];
        $expected = base_path('docs/report/SILANSIA_P9_4C_TEST_EVIDENCE_'.substr($guard->identity['database'], -16).'.jsonl');
        $this->assertSame($expected, getenv('SILANSIA_P94C_EVIDENCE_FILE'));
        $this->assertNotFalse(file_put_contents($expected, json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX));
    }
}
