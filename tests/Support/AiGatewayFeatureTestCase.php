<?php

namespace Tests\Support;

use App\Models\Camera;
use App\Models\CameraOperatingPeriod;
use App\Services\CameraOperatingPeriodService;
use App\Services\CameraService;
use App\Support\CameraVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class AiGatewayFeatureTestCase extends CameraFeatureTestCase
{
    // Kredensial sintetis khusus fixture; tidak pernah dipasang pada environment operasional.
    protected const SOURCE_ID = '00000000-0000-4000-8000-000000000001';

    protected const KEY_ID = '00000000-0000-4000-8000-000000000002';

    protected const SECRET = 'test-only-machine-key-00000000000000000000000000000000';

    protected Camera $testCamera;

    protected CameraOperatingPeriod $period;

    protected CarbonImmutable $eventTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testCamera = $this->camera();
        app(CameraService::class)->setActive($this->admin, $this->testCamera, CameraVersion::of($this->testCamera), true);
        $this->testCamera->refresh();
        $this->period = $this->testCamera->operatingPeriods()->sole();
        $this->eventTime = CameraOperatingPeriodService::serverTime()->subMinute();
        $this->period->opened_at = $this->eventTime->subHour();
        $this->period->save();
        $this->period->refresh();
        $this->configureSource();
        Auth::logout();
        $this->app['session']->driver()->flush();
    }

    protected function configureSource(array $changes = []): void
    {
        $source = array_replace(['id' => self::SOURCE_ID, 'enabled' => true, 'camera_ids' => [$this->testCamera->id],
            'event_types' => ['fall', 'gait'], 'keys' => [['id' => self::KEY_ID, 'enabled' => true, 'secret' => self::SECRET]]], $changes);
        config(['ai_gateway.enabled' => true, 'ai_gateway.audience' => 'silansia-test', 'ai_gateway.sources_json' => json_encode([$source], JSON_THROW_ON_ERROR),
            'ai_gateway.rates' => ['ip' => 5000, 'fall' => 5000, 'gait' => 5000]]);
    }

    protected function payload(string $type = 'fall', array $changes = []): array
    {
        return array_replace(['contract_version' => '1', 'external_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'camera_id' => $this->testCamera->id, 'camera_period_id' => $this->period->id,
            'occurred_at' => $this->eventTime->format('Y-m-d\TH:i:s.u\Z'), 'confidence' => '0.85000',
            ...($type === 'gait' ? ['category' => 'walking_normal'] : [])], $changes);
    }

    protected function endpoint(string $type = 'fall'): string
    {
        return '/api/ai/v1/'.($type === 'fall' ? 'fall-detections' : 'gait-observations');
    }

    protected function headersFor(string $body, string $path, array $changes = [], string $secret = self::SECRET, string $audience = 'silansia-test'): array
    {
        $headers = array_replace(['HTTP_X_SILANSIA_SOURCE' => self::SOURCE_ID, 'HTTP_X_SILANSIA_KEY' => self::KEY_ID,
            'HTTP_X_SILANSIA_TIMESTAMP' => (string) time(), 'HTTP_X_SILANSIA_NONCE' => bin2hex(random_bytes(32)),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $changes);
        // Signature dibuat dari spesifikasi wire secara independen dari helper produksi.
        $message = implode("\n", ['SILANSIA-AI-V1', $audience, 'POST', $path, $headers['HTTP_X_SILANSIA_SOURCE'], $headers['HTTP_X_SILANSIA_KEY'],
            $headers['HTTP_X_SILANSIA_TIMESTAMP'], $headers['HTTP_X_SILANSIA_NONCE'], hash('sha256', $body)]);
        $headers['HTTP_AUTHORIZATION'] = 'Silansia-HMAC-SHA256 '.hash_hmac('sha256', $message, $secret);

        return $headers;
    }

    protected function sendEvent(array $payload, array $headers = []): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $path = $this->endpoint($payload['event_type'] === 'gait' ? 'gait' : 'fall');

        return $this->call('POST', $path, [], [], [], array_replace($this->headersFor($body, $path), $headers), $body);
    }
}
