<?php

namespace Tests\Feature\AiGateway;

use App\Models\AiDetection;
use App\Services\CameraOperatingPeriodService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiGatewayFeatureTestCase;

class AiPayloadValidationTest extends AiGatewayFeatureTestCase
{
    public static function invalidValues(): array
    {
        return [
            ['contract_version', '2'], ['contract_version', 1], ['camera_id', 'not-uuid'], ['camera_period_id', 'invalid'],
            ['external_event_id', ''], ['external_event_id', true], ['confidence', 'NaN'], ['confidence', 'Infinity'], ['confidence', '-Infinity'],
            ['confidence', '-0.1'], ['confidence', '1.00001'], ['confidence', '0.123456'], ['confidence', '1e-1'], ['confidence', true],
            ['confidence', 0.5], ['confidence', ''], ['confidence', '  '], ['confidence', ' 0.5'], ['confidence', ['0.5']],
            ['occurred_at', '2026-02-30T00:00:00Z'], ['occurred_at', '2026-10-24 00:00:00'], ['occurred_at', '2026-10-24T00:00:00.1234567Z'],
            ['occurred_at', '2026-10-24T00:00:60Z'], ['occurred_at', '2026-10-24T00:00:00-00:00'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_fields_are_rejected_in_isolation(string $field, mixed $value): void
    {
        $this->sendEvent($this->payload(changes: [$field => $value]))->assertUnprocessable()->assertJsonPath('error.code', 'invalid_payload');
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_unknown_protected_fields_and_gait_category_cannot_be_injected(): void
    {
        foreach (['elderly_id', 'room_id', 'family_id', 'received_at', 'metadata', 'source_reference', 'verified_at', 'status'] as $field) {
            $this->sendEvent($this->payload(changes: [$field => 'private-forbidden-value']))->assertUnprocessable()->assertDontSee('private-forbidden-value');
        }
        $this->sendEvent($this->payload('gait', ['category' => 'clinical_score']))->assertUnprocessable();
        $this->sendEvent($this->payload('gait', ['event_type' => 'fall']))->assertUnprocessable(); // Category tidak diizinkan pada endpoint fall.
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_invalid_transport_and_body_are_rejected_without_echo(): void
    {
        foreach (['{broken-private', '[]', 'true'] as $body) {
            $this->call('POST', $this->endpoint(), [], [], [], $this->headersFor($body, $this->endpoint()), $body)->assertBadRequest()->assertDontSee('broken-private');
        }
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $headers = $this->headersFor($body, $this->endpoint());
        $this->call('POST', $this->endpoint(), [], [], [], [...$headers, 'CONTENT_TYPE' => 'text/plain'], $body)->assertUnsupportedMediaType();
        $this->call('POST', $this->endpoint().'?secret=private', [], [], [], $headers, $body)->assertUnsupportedMediaType()->assertDontSee('private');
        $large = str_repeat('x', 8193);
        $this->call('POST', $this->endpoint(), [], [], [], $this->headersFor($large, $this->endpoint()), $large)->assertStatus(413);
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public function test_missing_references_and_event_time_outside_coverage_are_rejected(): void
    {
        $missing = (string) Str::uuid();
        $this->configureSource(['camera_ids' => [$this->testCamera->id, $missing]]);
        $this->sendEvent($this->payload(changes: ['camera_id' => $missing]))->assertUnprocessable();
        $this->sendEvent($this->payload(changes: ['camera_period_id' => $missing]))->assertUnprocessable();
        foreach ([$this->eventTime->subDays(2), $this->eventTime->addDay(), $this->period->opened_at->subMicrosecond()] as $time) {
            $this->sendEvent($this->payload(changes: ['occurred_at' => $time->format('Y-m-d\TH:i:s.u\Z')]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('ai_detections', 0);
    }

    public static function validConfidence(): array
    {
        return [['0', '0.00000'], ['1', '1.00000'], ['0.12345', '0.12345'], [null, null]];
    }

    public function test_age_limit_and_inclusive_open_boundary_use_event_time(): void
    {
        $now = CameraOperatingPeriodService::serverTime();
        $this->period->update(['opened_at' => $now->subDays(3)]);
        $within = $now->subSeconds(86400 - 10);
        $this->sendEvent($this->payload(changes: ['occurred_at' => $within->format('Y-m-d\TH:i:s.u\Z')]))->assertCreated();
        $this->sendEvent($this->payload(changes: ['occurred_at' => $now->subSeconds(86401)->format('Y-m-d\TH:i:s.u\Z')]))->assertUnprocessable();
        $this->period->update(['opened_at' => $this->eventTime]);
        $this->sendEvent($this->payload())->assertCreated();
        $this->assertDatabaseCount('ai_detections', 2);
    }

    #[DataProvider('validConfidence')]
    public function test_valid_confidence_is_preserved_exactly(?string $value, ?string $expected): void
    {
        $this->sendEvent($this->payload(changes: ['confidence' => $value]))->assertCreated();
        $this->assertSame($expected, AiDetection::sole()->confidence);
    }
}
