<?php

namespace Tests\Feature\Database;

use App\Services\Billing\BillingTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceHttpTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('invoice-http')]
class InvoiceHttpConcurrencyTest extends InvoiceHttpTestCase
{
    public static function races(): array
    {
        return [['same-key'], ['different-key'], ['different-admin'], ['source-first'], ['ack-loss'], ['unauthorized-replay']];
    }

    #[DataProvider('races')]
    public function test_real_session_csrf_http_workers_serialize_issue_source_and_replay(string $race): void
    {
        $s = $this->source();
        $one = $this->httpCommand($s);
        $two = $race === 'different-key' ? $this->httpCommand($s) : $one;
        $other = $s;
        if ($race === 'different-admin') {
            $other['admin'] = $this->account();
            $this->flushSession();
            $two = $this->httpCommand($other);
        }
        $before = $this->fingerprints();
        if ($race === 'source-first') {
            $first = $this->spawn(['operation' => 'transfer', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id,
                'expected' => $s['family']->id, 'family' => $s['next']->id, 'pause' => ['before_commit:1']]);
        } elseif ($race === 'unauthorized-replay') {
            $this->actingAs($s['admin'])->postJson(self::HTTP_BASE, $one)->assertCreated();
            $first = $this->spawn(['operation' => 'actor_role', 'target' => $s['admin']->id, 'pause' => ['before_commit:1']]);
        } else {
            $first = $this->spawn($this->httpPayload($s, $one, '', ['before_commit:2'], $race === 'ack-loss' ? 'ack_loss' : ''));
        }
        $second = $this->spawn($this->httpPayload($other, $two));
        $this->release($first);
        $this->awaitPoint($first, 'before_commit', in_array($race, ['source-first', 'unauthorized-replay'], true) ? 1 : 2);
        $this->release($second);
        // HTTP preflight is read-only attempt1; writer is attempt2.
        $overlap = $this->blockedBy($second, $first, 2);
        $this->release($first);
        $a = $this->finish($first);
        $b = $this->finish($second);
        if ($race === 'source-first') {
            $this->assertTrue($a['ok']);
            $this->assertSame(409, $b['http_status']);
            $this->assertSame('preview_stale', $b['kind']);
        } elseif ($race === 'unauthorized-replay') {
            $this->assertTrue($a['ok']);
            $this->assertSame(403, $b['http_status']);
            $this->assertNull($b['invoice']);
        } else {
            $this->assertSame($race === 'ack-loss' ? 200 : 201, $a['http_status']);
            $this->assertSame('ISSUE', $a['decision']);
            $this->assertSame(in_array($race, ['same-key', 'ack-loss'], true) ? 200 : 409, $b['http_status']);
            if (in_array($race, ['same-key', 'ack-loss'], true)) {
                $this->assertTrue($b['replayed']);
                $this->assertSame($a['invoice'], $b['invoice']);
            } else {
                $this->assertSame('invoice_exists', $b['kind']);
            }
        }
        foreach ([$a, $b] as $outcome) {
            if (isset($outcome['http_status'])) {
                $this->assertTrue($outcome['csrf_enforced']);
                $this->assertTrue($outcome['no_store']);
            }
        }
        $this->assertCount($race === 'source-first' ? 0 : 1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'sessions',
            ...($race === 'source-first' ? ['elderlies', 'financial_ownership_periods'] : []), ...($race === 'unauthorized-replay' ? ['users'] : [])]);
        $this->evidence('HTTP-'.$race, [$a, $b], $before, $overlap);
    }

    public function test_candidate_cannot_be_deleted_updated_or_truncated_between_lookup_commit_and_replay(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $id = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->json('invoice_id');
        $before = $this->fingerprints();
        $worker = $this->spawn($this->httpPayload($s, $this->reconcileBody($this->expired($body)), '/reconcile', ['committed:1']));
        $this->release($worker);
        $this->awaitPoint($worker, 'committed'); // existence lookup TX already committed, service not yet called
        foreach (['DELETE FROM public.billing_requests WHERE actor_id=? AND request_id=?',
            'UPDATE public.billing_requests SET request_id=request_id WHERE actor_id=? AND request_id=?', 'TRUNCATE public.billing_requests CASCADE'] as $sql) {
            PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
            try {
                DB::statement($sql, str_starts_with($sql, 'TRUNCATE') ? [] : [$s['admin']->id, $body['request_id']]);
                $this->fail('Immutable request tidak boleh dimutasi.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString(str_starts_with($sql, 'TRUNCATE') ? 'billing_history_truncate_forbidden' : 'billing_request_immutable', $error->errorInfo[2]);
            }
        }
        $this->release($worker);
        $outcome = $this->finish($worker);
        $this->assertSame(200, $outcome['http_status']);
        $this->assertTrue($outcome['replayed']);
        $this->assertSame($id, $outcome['invoice']);
        $this->preserve($before, ['sessions']);
        $this->evidence('HTTP-immutable-existence-replay', [$outcome], $before, ['barrier_after_lookup_commit' => true, 'mutations_rejected' => 3]);
    }

    public static function unknownCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('unknownCases')]
    public function test_reconcile_during_uncommitted_issue_is_unresolved_then_replays_without_any_new_graph(bool $unknown): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->httpPayload($s, $body, '', ['before_commit:2'], $unknown ? 'ack_always' : ''));
        $reconcile = $this->spawn($this->httpPayload($s, $this->reconcileBody($this->expired($body)), '/reconcile'));
        $this->release($issuer);
        $this->awaitPoint($issuer, 'before_commit', 2);
        $this->release($reconcile);
        $b = $this->finish($reconcile);
        $this->assertSame(409, $b['http_status']);
        $this->assertSame('outcome_unresolved', $b['kind']);
        $this->assertCount(0, array_filter($this->events($reconcile), fn ($e) => $e['point'] === 'root_inserted' || $e['point'] === 'lock_users'));
        $this->release($issuer);
        $a = $this->finish($issuer);
        $this->assertSame($unknown ? 503 : 201, $a['http_status']);
        if ($unknown) {
            $this->assertSame('outcome_unknown', $a['kind']);
            $this->assertNull($a['invoice']);
        }
        $afterIssue = $this->fingerprints();
        $again = $this->spawn($this->httpPayload($s, $this->reconcileBody($this->expired($body)), '/reconcile'));
        $this->release($again);
        $c = $this->finish($again);
        $this->assertSame(200, $c['http_status']);
        $this->assertTrue($c['replayed']);
        $this->preserve($afterIssue, ['sessions']);
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->evidence('HTTP-'.($unknown ? 'unknown-commit' : 'uncommitted').'-absent-then-replay', [$a, $b, $c], $before, ['issuer_open_at_reconcile' => true]);
    }

    public function test_admitted_token_can_expire_while_writer_waits_without_rechecking_expiry_at_commit(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $issuedAt = DB::scalar("SELECT to_char((clock_timestamp()-INTERVAL '297 seconds') AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");
        $expiresAt = BillingTime::format(BillingTime::instant($issuedAt)->modify('+5 minutes'));
        $body = $this->changedToken($body, ['issued_at' => $issuedAt, 'expires_at' => $expiresAt]);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->httpPayload($s, $body, '', ['preread:1']));
        $this->release($issuer);
        $this->awaitPoint($issuer, 'preread'); // admission passed, no transaction wrapper around issue
        $deadline = microtime(true) + 4;
        do {
            $expired = DB::scalar('SELECT clock_timestamp()>=CAST(? AS timestamptz)', [$expiresAt]);
            if ($expired) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertTrue($expired);
        $this->release($issuer);
        $a = $this->finish($issuer);
        $this->assertSame(201, $a['http_status']);
        $this->assertTrue(DB::scalar('SELECT initial_issued_at>=CAST(? AS timestamptz) FROM invoices WHERE id=?', [$expiresAt, $a['invoice']]));
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'sessions']);
        $this->evidence('HTTP-expiry-after-admission', [$a], $before, ['expired_before_writer_transaction' => true]);
    }

    public function test_read_page_and_total_keep_one_readonly_snapshot_when_another_invoice_commits(): void
    {
        $s = $this->source(['start' => '2026-09-01 00:00:00+07', 'from' => '2026-08-01 00:00:00+07']);
        $body = $this->httpCommand($s);
        $this->postJson(self::HTTP_BASE, $body)->assertCreated();
        $tuple = ['elderly_id' => $s['elderly']->id, 'billing_month' => '2026-09-01'];
        $preview = $this->postJson(self::HTTP_BASE.'/preview', $tuple)->assertOk();
        $secondBody = [...$tuple, 'confirm' => true, 'request_id' => $preview->json('confirmation.request_id'), 'confirmation_token' => $preview->json('confirmation.token')];
        $before = $this->fingerprints();
        $read = $this->spawn([...$this->httpPayload($s, [], '', ['http_snapshot:1']), 'method' => 'GET',
            'path' => self::HTTP_BASE.'?elderly_id='.$s['elderly']->id]);
        $writer = $this->spawn($this->httpPayload($s, $secondBody));
        $this->release($read);
        $this->assertSame('on', $this->awaitPoint($read, 'http_snapshot')['read_only']);
        $this->release($writer);
        $a = $this->finish($writer);
        $this->assertSame(201, $a['http_status']);
        $this->release($read);
        $b = $this->finish($read);
        $this->assertSame(200, $b['http_status']);
        $this->assertSame(1, $b['read_total']);
        $this->assertSame(1, $b['read_count']);
        $this->get(self::HTTP_BASE.'?elderly_id='.$s['elderly']->id, $this->inertiaHeaders())->assertOk()
            ->assertJsonPath('props.invoices.total', 2)->assertJsonPath('props.invoices.data.0.billing_month', '2026-10-01');
        $this->assertCount(2, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'sessions']);
        $this->evidence('HTTP-readonly-page-total-snapshot', [$a, $b], $before, ['read_snapshot_open_when_second_invoice_committed' => true]);
    }

    public function test_lost_ack_after_commit_does_not_disclose_outcome_when_actor_role_is_revoked(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $issuer = $this->spawn($this->httpPayload($s, $body, '', ['committed:2'], 'ack_loss'));
        $change = $this->spawn(['operation' => 'actor_role', 'target' => $s['admin']->id]);
        $this->release($issuer);
        $this->awaitPoint($issuer, 'committed', 2);
        $this->release($change);
        $a = $this->finish($change);
        $this->assertTrue($a['ok']);
        $this->release($issuer);
        $b = $this->finish($issuer);
        $this->assertSame(403, $b['http_status']);
        $this->assertNull($b['invoice']);
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, ['invoices', 'invoice_revisions', 'billing_requests', 'billing_actions', 'sessions', 'users']);
        $this->evidence('HTTP-committed-ack-loss-auth-revoked', [$a, $b], $before, ['commit_before_role_revocation' => true]);
    }
}
