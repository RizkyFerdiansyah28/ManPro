<?php

namespace Tests\Feature\AiGateway;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiGatewayFeatureTestCase;

class AiAuthenticationTest extends AiGatewayFeatureTestCase
{
    public static function failures(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'signature', 'source', 'key', 'source_disabled', 'key_disabled', 'body', 'path', 'audience', 'stale', 'future', 'nonce_format']);
    }

    #[DataProvider('failures')]
    public function test_machine_authentication_fails_without_domain_writes(string $case): void
    {
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $path = $this->endpoint();
        $changes = match ($case) {
            'source' => ['HTTP_X_SILANSIA_SOURCE' => (string) Str::uuid()],
            'key' => ['HTTP_X_SILANSIA_KEY' => (string) Str::uuid()],
            'stale' => ['HTTP_X_SILANSIA_TIMESTAMP' => (string) (time() - 301)],
            'future' => ['HTTP_X_SILANSIA_TIMESTAMP' => (string) (time() + 302)],
            'nonce_format' => ['HTTP_X_SILANSIA_NONCE' => 'bad-nonce'], default => []
        };
        if ($case === 'source_disabled') {
            $this->configureSource(['enabled' => false]);
        }
        if ($case === 'key_disabled') {
            $this->configureSource(['keys' => [['id' => self::KEY_ID, 'secret' => self::SECRET, 'enabled' => false]]]);
        }
        $headers = $this->headersFor($body, $case === 'path' ? $this->endpoint('gait') : $path, $changes, audience: $case === 'audience' ? 'other-environment' : 'silansia-test');
        if ($case === 'missing') {
            unset($headers['HTTP_AUTHORIZATION']);
        }
        if ($case === 'signature') {
            $headers['HTTP_AUTHORIZATION'] = 'Silansia-HMAC-SHA256 '.str_repeat('0', 64);
        }
        if ($case === 'body') {
            $body .= ' ';
        }
        $this->call('POST', $path, [], [], [], $headers, $body)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated_source')
            ->assertDontSee(self::SECRET)->assertDontSee('trace');
        $this->assertDatabaseCount('ai_detections', 0);
        $this->assertDatabaseCount('fall_incidents', 0);
        $this->assertDatabaseCount('gait_observations', 0);
    }

    public function test_replay_nonce_is_rejected_but_retry_with_fresh_nonce_is_idempotent(): void
    {
        $payload = $this->payload();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->headersFor($body, $this->endpoint());
        $first = $this->call('POST', $this->endpoint(), [], [], [], $headers, $body)->assertCreated();
        $this->call('POST', $this->endpoint(), [], [], [], $headers, $body)->assertUnauthorized();
        $this->sendEvent($payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('detection_id', $first->json('detection_id'));
        $this->assertDatabaseCount('ai_detections', 1);
    }

    public function test_source_scope_is_enforced_before_persistence(): void
    {
        $this->configureSource(['event_types' => ['gait']]);
        $this->sendEvent($this->payload())->assertForbidden();
        $this->configureSource(['camera_ids' => []]);
        $this->sendEvent($this->payload())->assertForbidden()->assertDontSee($this->testCamera->room->name);
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_gateway_and_cache_fail_closed_without_valid_configuration(): void
    {
        config(['ai_gateway.enabled' => false]);
        $this->sendEvent($this->payload())->assertServiceUnavailable();
        config(['ai_gateway.enabled' => true, 'ai_gateway.sources_json' => 'invalid-json']);
        $this->sendEvent($this->payload())->assertServiceUnavailable()->assertDontSee(self::SECRET);
        $this->configureSource();
        config(['ai_gateway.cache_store' => 'array']);
        $this->sendEvent($this->payload())->assertServiceUnavailable();
        config(['ai_gateway.cache_store' => 'database']);
        Schema::rename('cache', 'cache_unavailable_test');
        DB::statement('SAVEPOINT cache_failure_request');
        $response = $this->sendEvent($this->payload());
        DB::statement('ROLLBACK TO SAVEPOINT cache_failure_request');
        $response->assertServiceUnavailable()->assertDontSee('SQLSTATE');
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_human_sessions_never_substitute_for_machine_credentials(): void
    {
        foreach (['admin', 'perawat', 'keluarga'] as $role) {
            $this->actingAs($this->account($role));
            $this->postJson($this->endpoint(), $this->payload())->assertUnauthorized();
        }
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_cache_cannot_be_redirected_to_the_development_connection(): void
    {
        // Hanya mengubah konfigurasi fixture; middleware wajib menolak sebelum memakai koneksi ini.
        config(['cache.stores.database.connection' => 'pgsql']);
        $this->sendEvent($this->payload())->assertServiceUnavailable();
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_machine_signature_does_not_grant_access_to_admin_web_routes(): void
    {
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $this->call('GET', '/admin/cameras', [], [], [], $this->headersFor($body, $this->endpoint()), $body)->assertUnauthorized();
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_key_rotation_preserves_event_identity_and_source_namespaces_are_independent(): void
    {
        $payload = $this->payload();
        $first = $this->sendEvent($payload)->assertCreated();
        $secondKey = (string) Str::uuid();
        $this->configureSource(['keys' => [['id' => $secondKey, 'enabled' => true, 'secret' => self::SECRET]]]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->headersFor($body, $this->endpoint(), ['HTTP_X_SILANSIA_KEY' => $secondKey]);
        $this->call('POST', $this->endpoint(), [], [], [], $headers, $body)->assertOk()->assertJsonPath('detection_id', $first->json('detection_id'));
        $secondSource = (string) Str::uuid();
        $this->configureSource(['id' => $secondSource]);
        $headers = $this->headersFor($body, $this->endpoint(), ['HTTP_X_SILANSIA_SOURCE' => $secondSource]);
        $this->call('POST', $this->endpoint(), [], [], [], $headers, $body)->assertCreated();
        $this->assertDatabaseCount('ai_detections', 2);
    }

    public function test_rate_buckets_are_separate_and_https_is_required_outside_local_testing(): void
    {
        config(['ai_gateway.rates.fall' => 1]);
        $this->sendEvent($this->payload())->assertCreated();
        $this->sendEvent($this->payload())->assertTooManyRequests()->assertHeader('Retry-After');
        $this->sendEvent($this->payload('gait'))->assertCreated();
        $this->app->instance('env', 'production');
        try {
            $this->sendEvent($this->payload())->assertBadRequest()->assertJsonPath('error.code', 'https_required');
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame(2, DB::table('ai_detections')->count());
    }
}
