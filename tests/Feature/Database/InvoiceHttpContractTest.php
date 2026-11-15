<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceHttpTestCase;

#[Group('invoice-http')]
class InvoiceHttpContractTest extends InvoiceHttpTestCase
{
    public static function deniedActors(): array
    {
        return [[null, true, 401], ['keluarga', true, 403], ['perawat', true, 403], ['admin', false, 401], ['changed', true, 403]];
    }

    #[DataProvider('deniedActors')]
    public function test_all_seven_endpoints_deny_unauthorized_sessions(?string $role, bool $active, int $status): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $id = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->json('invoice_id');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        if ($role !== null) {
            $user = $this->account($role === 'changed' ? 'admin' : $role, $active);
            if ($role === 'changed') {
                DB::table('users')->where('id', $user->id)->update(['role' => 'perawat']);
                $user = $user->fresh();
            }
            $this->actingAs($user);
        }
        $before = $this->fingerprints();
        foreach ([['GET', '', []], ['GET', '/create', []], ['GET', '/'.$id, []], ['GET', '/resident-options', []],
            ['POST', '/preview', $this->previewBody($s)], ['POST', '', $body], ['POST', '/reconcile', $this->reconcileBody($body)]] as [$method, $path, $data]) {
            $response = $this->json($method, self::HTTP_BASE.$path, $data)->assertStatus($role === 'admin' ? 401 : $status);
            $this->assertNoPrivateError($response);
            $this->assertArrayNotHasKey('invoice_id', $response->json());
        }
        // Inactive logout legitimately rotates its remember token; no financial/domain edits.
        $this->preserve($before, $role === 'admin' ? ['users'] : []);
    }

    public function test_inertia_page_contracts_readonly_snapshot_and_legacy_never_infer_current_owner(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $id = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->json('invoice_id');
        $legacy = $this->source();
        $legacyId = $this->billingId();
        DB::table('invoices')->insert(['id' => $legacyId, 'number' => 'test-legacy-'.$legacyId,
            'elderly_id' => $legacy['elderly']->id, 'service_enrollment_id' => $legacy['enrollment']->id,
            'billing_month' => '2026-10-01', 'amount' => '3100.00', 'currency' => 'IDR', 'status' => 'unpaid',
            'due_date' => '2026-11-08', 'grace_period_ends_on' => '2026-11-11']);
        $before = $this->fingerprints();
        $headers = $this->inertiaHeaders();
        foreach (['' => 'Index', '/create' => 'Create', '/'.$id => 'Show'] as $suffix => $page) {
            // Prior integrity/temporal suites retain committed negative fixtures. The positive
            // index contract must select this valid source, not every graph in the shared DB.
            $url = self::HTTP_BASE.$suffix.($page === 'Index' ? '?elderly_id='.$s['elderly']->id : '');
            $response = $this->get($url)->assertOk()
                ->assertInertia(fn ($assert) => $assert->component('Admin/Billing/Invoices/'.$page, false));
            $this->assertNoPrivateError($response);
            $this->get($url, $headers)->assertOk()->assertJsonPath('component', 'Admin/Billing/Invoices/'.$page);
        }
        $this->get(self::HTTP_BASE.'/'.$legacyId, $headers)->assertOk()
            ->assertJsonPath('props.invoice.record_kind', 'legacy')->assertJsonPath('props.invoice.current_revision', null)
            ->assertJsonPath('props.invoice.financial_owner', null)->assertJsonPath('props.invoice.actions', []);
        $this->get(self::HTTP_BASE.'?'.http_build_query(['elderly_id' => $s['elderly']->id, 'page' => 999, 'per_page' => 15, 'sort' => 'due_date_asc']), $headers)
            ->assertOk()->assertJsonPath('props.invoices.total', 1)->assertJsonPath('props.invoices.data', []);
        $this->get(self::HTTP_BASE.'?'.http_build_query(['elderly_id' => $s['elderly']->id, 'status' => 'unpaid', 'billing_month' => '2026-10-01']), $headers)
            ->assertOk()->assertJsonPath('props.invoices.total', 1)->assertJsonPath('props.invoices.data.0.id', $id);
        $this->getJson(self::HTTP_BASE.'/resident-options?per_page=15')->assertOk()->assertJsonStructure(['data', 'total', 'per_page', 'next_page_url']);
        $this->preserve($before, []);
    }

    public static function previewCases(): array
    {
        return [[[], 'ISSUE'], [['price' => '0.00'], 'ISSUE'], [['price' => null], 'HOLD'], [['no_service' => true], 'SKIP'],
            [['no_ledger' => true], 'HOLD'], [['end' => '2026-10-05 00:00:00+07', 'active' => false], 'ISSUE']];
    }

    #[DataProvider('previewCases')]
    public function test_preview_decisions_never_write_financial_records(array $changes, string $decision): void
    {
        $s = $this->source($changes);
        $before = $this->fingerprints();
        $response = $this->actingAs($s['admin'])->postJson(self::HTTP_BASE.'/preview', $this->previewBody($s))
            ->assertOk()->assertJsonPath('decision', $decision)->assertJsonPath('issued', false);
        if ($decision !== 'ISSUE') {
            $response->assertJsonPath('confirmation', null)->assertJsonPath('calculation', null);
        } else {
            $this->assertIsString($response->json('confirmation.token'));
            $this->assertSame($changes['price'] ?? '3100.00', $response->json('calculation.monthly_price'));
        }
        $this->assertNoPrivateError($response);
        $this->preserve($before, []);
    }

    public function test_future_month_preview_rejects_without_a_confirmation(): void
    {
        $s = $this->source();
        $month = DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'Asia/Jakarta','YYYY-MM-01')");
        $before = $this->fingerprints();
        $this->actingAs($s['admin'])->postJson(self::HTTP_BASE.'/preview', ['elderly_id' => $s['elderly']->id, 'billing_month' => $month])
            ->assertOk()->assertJsonPath('decision', 'REJECT')->assertJsonPath('confirmation', null)->assertJsonPath('calculation', null);
        $this->preserve($before, []);
    }

    public static function badBodies(): array
    {
        $cases = [];
        foreach (['actor_id', 'family_id', 'owner_id', 'amount', 'rate', 'issued_at', 'status', 'connection', 'gate', 'metadata'] as $field) {
            $cases['forbidden-'.$field] = [$field, 'untrusted', false];
        }
        foreach (['elderly_id' => 'not-uuid', 'billing_month' => '2026-10-02', 'request_id' => 'no-uuid', 'confirmation_token' => 'bad-token',
            'confirm' => false] as $field => $value) {
            $cases['invalid-'.$field] = [$field, $value, true];
        }

        return $cases;
    }

    #[DataProvider('badBodies')]
    public function test_each_invalid_field_is_isolated_from_an_otherwise_valid_issue(string $field, mixed $value, bool $replace): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $body[$field] = $value;
        $response = $this->postJson(self::HTTP_BASE, $body)->assertStatus(422);
        $this->assertNoPrivateError($response);
        $this->preserve($before, []);
    }

    public static function badEnvelopes(): array
    {
        return [['actor_id'], ['elderly_id'], ['billing_month'], ['request_id'], ['audience'], ['purpose'], ['version'], ['extra'], ['source_fingerprint'], ['expires_at'], ['issued_at'], ['oversize'], ['ciphertext']];
    }

    #[DataProvider('badEnvelopes')]
    public function test_encrypted_token_schema_binding_integrity_and_size(string $field): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $value = match ($field) {
            'actor_id', 'elderly_id', 'request_id' => $this->billingId(), 'billing_month' => '2026-09-01',
            'version' => '1', 'source_fingerprint' => [], 'issued_at' => '2999-01-01T00:00:00Z', default => 'invalid',
        };
        if ($field === 'oversize') {
            $body['confirmation_token'] = str_repeat('x', 4097);
        } elseif ($field === 'ciphertext') {
            $body['confirmation_token'][30] = $body['confirmation_token'][30] === 'a' ? 'b' : 'a';
        } else {
            $body = $this->changedToken($body, [$field => $value]);
        }
        $response = $this->postJson(self::HTTP_BASE, $body)->assertStatus(422);
        $this->assertNoPrivateError($response);
        $this->preserve($before, []);
    }

    public function test_committed_replay_expired_reconciliation_absence_conflict_and_zero_charge(): void
    {
        $s = $this->source(['price' => '0.00']);
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $expired = $this->expired($body);
        $this->postJson(self::HTTP_BASE, $expired)->assertStatus(409)->assertJsonPath('error.code', 'confirmation_expired');
        $this->postJson(self::HTTP_BASE.'/reconcile', $this->reconcileBody($expired))->assertStatus(409)->assertJsonPath('error.code', 'outcome_unresolved');
        $this->preserve($before, []);
        $first = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->assertJsonPath('amount', '0.00');
        $before = $this->fingerprints();
        foreach ([$body, $expired] as $command) {
            $this->postJson(self::HTTP_BASE.'/reconcile', $this->reconcileBody($command))->assertOk()
                ->assertJsonPath('invoice_id', $first->json('invoice_id'))->assertJsonPath('replayed', true);
        }
        $this->postJson(self::HTTP_BASE, $body)->assertOk()->assertJsonPath('replayed', true);
        $differentIntent = $this->changedToken($body, ['source_fingerprint' => str_repeat('a', 64)]);
        $this->postJson(self::HTTP_BASE, $differentIntent)->assertStatus(409)->assertJsonPath('error.code', 'request_conflict');
        $this->postJson(self::HTTP_BASE.'/reconcile', $this->reconcileBody($differentIntent))->assertStatus(409)->assertJsonPath('error.code', 'request_conflict');
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, []);
    }

    public function test_stale_preview_does_not_refresh_fingerprint_and_new_request_conflicts_with_existing_root(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $this->sourceTransfer($s, '2026-11-02 00:00:00.123456+07');
        $before = $this->fingerprints();
        $this->postJson(self::HTTP_BASE, $body)->assertStatus(409)->assertJsonPath('error.code', 'preview_stale');
        $this->preserve($before, []);
        $body = $this->httpCommand($s);
        $otherBody = $this->httpCommand($s);
        $id = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->json('invoice_id');
        $before = $this->fingerprints();
        $this->postJson(self::HTTP_BASE, $otherBody)->assertStatus(409)->assertJsonPath('error.code', 'invoice_exists');
        $this->assertSame($s['family']->id, DB::table('invoices')->where('id', $id)->value('bill_to_family_id'));
        $this->preserve($before, []);
    }

    public static function readinessCases(): array
    {
        return [['gate'], ['environment'], ['connection'], ['reference'], ['target_context'], ['schema'], ['guard']];
    }

    #[DataProvider('readinessCases')]
    public function test_readiness_refuses_before_any_billing_domain_lookup(string $case): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $config = ['invoice_issuance.enabled' => config('invoice_issuance.enabled'), 'app.env' => config('app.env'),
            'database.default' => config('database.default'), 'financial_ownership.cutover_reference' => config('financial_ownership.cutover_reference')];
        $environment = $this->app['env'];
        $identity = getenv('SILANSIA_TEST_IDENTITY');
        $this->withSession(['_token' => 'readiness-csrf']);
        match ($case) {
            'gate' => config(['invoice_issuance.enabled' => false]),
            'environment' => $this->app->instance('env', 'local'),
            'connection' => config(['database.default' => 'undefined_connection']),
            'reference' => config(['financial_ownership.cutover_reference' => '']),
            'target_context' => putenv('SILANSIA_TEST_IDENTITY='.json_encode([...json_decode($identity, true), 'oid' => '0'])),
            'schema' => DB::statement('ALTER TABLE public.billing_requests ADD COLUMN test_http_extra text'),
            'guard' => DB::statement('ALTER TABLE public.billing_requests DISABLE TRIGGER billing_request_write'),
        };
        $lookups = [];
        DB::connection('silansia_testing')->listen(function ($query) use (&$lookups) {
            if (preg_match('/(?:from|join)\s+(?:public\.)?"?(invoices|invoice_revisions|billing_requests|billing_actions|financial_ownership_periods)"?\b/i', $query->sql)) {
                $lookups[] = $query->sql;
            }
        });
        try {
            foreach ([['GET', '/'.$this->billingId(), []], ['POST', '/preview', $this->previewBody($s)], ['POST', '', $body],
                ['POST', '/reconcile', $this->reconcileBody($body)]] as [$method, $path, $payload]) {
                $response = $this->json($method, self::HTTP_BASE.$path, $payload, ['X-CSRF-TOKEN' => 'readiness-csrf'])->assertStatus(503);
                $this->assertNoPrivateError($response);
            }
            $this->assertSame([], $lookups);
        } finally {
            config($config);
            $this->app->instance('env', $environment);
            putenv('SILANSIA_TEST_IDENTITY='.$identity);
            if ($case === 'schema') {
                DB::statement('ALTER TABLE public.billing_requests DROP COLUMN test_http_extra');
            } elseif ($case === 'guard') {
                DB::statement('ALTER TABLE public.billing_requests ENABLE TRIGGER billing_request_write');
            }
        }
        $this->preserve($before, []);
    }

    public function test_real_csrf_format_errors_and_rate_limit_are_private_and_do_not_disable_monitoring(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->withSession(['_token' => 'test-csrf-http']);
        $missing = $this->postJson(self::HTTP_BASE.'/preview', $this->previewBody($s))->assertStatus(419);
        $this->assertNoPrivateError($missing);
        $valid = ['X-CSRF-TOKEN' => 'test-csrf-http'];
        $this->postJson(self::HTTP_BASE.'/preview', $this->previewBody($s), $valid)->assertOk();
        $wrongType = $this->post(self::HTTP_BASE.'/preview', ['_token' => 'test-csrf-http'], ['Accept' => 'application/json'])->assertStatus(415);
        $this->assertNoPrivateError($wrongType);
        $wrongMethod = $this->deleteJson(self::HTTP_BASE, [], $valid)->assertStatus(405);
        $this->assertNoPrivateError($wrongMethod);
        $this->assertNotNull($wrongMethod->headers->get('Allow'));
        foreach (range(1, 6) as $n) {
            $this->postJson(self::HTTP_BASE, $body, $valid)->assertStatus($n === 1 ? 201 : 200);
        }
        $limited = $this->postJson(self::HTTP_BASE, $body, $valid)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertNoPrivateError($limited);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->getJson('/notifications/summary')->assertOk();
    }

    public static function badQueries(): array
    {
        return [['family_id=hidden'], ['sort=amount%3BDROP%20TABLE'], ['page=10001'], ['per_page=1000'], ['billing_month=2026-10-02'], ['confirmation_token=SECRET-QUERY']];
    }

    #[DataProvider('badQueries')]
    public function test_filters_and_error_urls_never_echo_untrusted_queries(string $query): void
    {
        $s = $this->source();
        $response = $this->actingAs($s['admin'])->get(self::HTTP_BASE.'?'.$query, $this->inertiaHeaders())->assertStatus(422);
        $this->assertNoPrivateError($response);
        $this->assertStringNotContainsString('SECRET-QUERY', $response->getContent());
        $this->assertStringNotContainsString($query, $response->getContent());
        $this->assertFalse(str_contains(json_encode(session()->all()), 'SECRET-QUERY'));
    }

    public function test_broken_managed_source_is_closed_without_weakening_guards_or_editing_invoice_history(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $id = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->json('invoice_id');
        $before = $this->fingerprints();
        // Negative privileged enrollment fixture ONLY: no trigger changed, no SQL issuance/backdate workflow.
        DB::table('service_enrollments')->where('id', $s['enrollment']->id)->update(['ended_at' => '2026-10-05 00:00:00+07']);
        $corrupt = $this->fingerprints();
        $response = $this->getJson(self::HTTP_BASE.'/'.$id)->assertStatus(500)->assertJsonPath('error.code', 'billing_integrity_error');
        $this->assertNoPrivateError($response);
        $this->preserve($corrupt, []);
        DB::table('service_enrollments')->where('id', $s['enrollment']->id)->update(['ended_at' => null]);
        $this->preserve($before, []);
        $this->get(self::HTTP_BASE.'/'.$id, $this->inertiaHeaders())->assertOk();
    }

    public function test_protocol_and_unmatched_error_paths_do_not_reflect_tokens_into_headers_props_or_session(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $token = $body['confirmation_token'];
        $badPath = $this->get(self::HTTP_BASE.'/'.$token, $this->inertiaHeaders())->assertNotFound();
        $this->assertStringNotContainsString($token, $badPath->getContent());
        $reload = $this->get(self::HTTP_BASE.'?sort='.urlencode($token), ['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'])->assertStatus(409);
        $this->assertSame(self::HTTP_BASE, $reload->headers->get('X-Inertia-Location'));
        $this->assertFalse(str_contains(json_encode(session()->all()), $token));
        $this->assertNoPrivateError($reload);
    }

    public function test_json_command_negotiation_bounds_targets_and_integrity_errors_are_private(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $before = $this->fingerprints();
        $this->postJson(self::HTTP_BASE.'/preview', $this->previewBody($s), ['X-Inertia' => 'true'])->assertStatus(406);
        $this->postJson(self::HTTP_BASE.'/preview', $this->previewBody($s), ['Accept' => 'text/html'])->assertStatus(406);
        $this->call('POST', self::HTTP_BASE.'/preview', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{"bad":')
            ->assertStatus(422);
        $this->postJson(self::HTTP_BASE.'/preview', ['junk' => str_repeat('x', 8193)])->assertStatus(413);
        $this->postJson(self::HTTP_BASE.'/preview', [...$this->previewBody($s), 'elderly_id' => $this->billingId()])->assertNotFound();
        $this->postJson(self::HTTP_BASE.'/payments', $body)->assertNotFound();
        $this->preserve($before, []);
        $this->fault('issued');
        $error = $this->postJson(self::HTTP_BASE, $body)->assertStatus(500)->assertJsonPath('error.code', 'billing_integrity_error');
        $this->assertNoPrivateError($error);
        $this->assertStringNotContainsString($body['confirmation_token'], $error->getContent());
        $this->preserve($before, []);
    }
}
