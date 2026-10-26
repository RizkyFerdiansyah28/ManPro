<?php

namespace App\Support\AiGateway;

use App\Exceptions\AiGatewayException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

final readonly class EventPayload
{
    public array $values;

    public CarbonImmutable $occurredAt;

    public function __construct(array $validated)
    {
        $this->occurredAt = self::timestamp($validated['occurred_at']);
        $confidence = $validated['confidence'] ?? null;
        if ($confidence !== null) {
            $parts = explode('.', $confidence);
            $confidence = $parts[0].'.'.str_pad($parts[1] ?? '', 5, '0');
        }
        $this->values = [...$validated, 'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.u\Z'), 'confidence' => $confidence, 'category' => $validated['category'] ?? null];
    }

    public static function timestamp(string $value): CarbonImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-2][0-9]:[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?(?:Z|[+-](?:0[0-9]|1[0-9]|2[0-3]):[0-5][0-9])\z/', $value)
            || str_ends_with($value, '-00:00') || str_starts_with($value, '0000')) {
            self::invalidTimestamp();
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $offset = substr($normalized, -6);
        $base = substr($normalized, 0, -6);
        $parts = explode('.', $base);
        $normalized = $parts[0].'.'.str_pad($parts[1] ?? '', 6, '0').$offset;
        try {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
            $errors = DateTimeImmutable::getLastErrors();
            if (! $parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $parsed->format('Y-m-d\TH:i:s.uP') !== $normalized) {
                self::invalidTimestamp();
            }

            return CarbonImmutable::instance($parsed)->utc();
        } catch (AiGatewayException $error) {
            throw $error;
        } catch (Throwable) {
            self::invalidTimestamp();
        }
    }

    public function externalId(string $sourceId): string
    {
        return 'v1:'.$sourceId.':'.$this->values['external_event_id'];
    }

    public function hash(string $sourceId): string
    {
        $v = $this->values;

        return hash('sha256', json_encode([1, $v['contract_version'], $sourceId, $v['external_event_id'], $v['event_type'],
            $v['camera_id'], $v['camera_period_id'], $v['occurred_at'], $v['confidence'], $v['category']], JSON_THROW_ON_ERROR));
    }

    private static function invalidTimestamp(): never
    {
        throw new AiGatewayException(422, 'invalid_payload', 'Timestamp event tidak valid.', ['occurred_at' => ['Gunakan RFC3339 dengan zona waktu dan maksimal enam digit fraksi.']]);
    }
}
