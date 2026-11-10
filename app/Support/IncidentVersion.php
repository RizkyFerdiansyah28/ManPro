<?php

namespace App\Support;

use App\Exceptions\IncidentConflictException;
use App\Models\FallIncident;

final class IncidentVersion
{
    public const FIELDS = ['id', 'ai_detection_id', 'elderly_id', 'status', 'responding_caregiver_id',
        'acknowledged_at', 'verified_by', 'verified_at', 'resolved_at', 'notes', 'created_at', 'updated_at'];

    public static function of(FallIncident $incident): string
    {
        return self::ofAttributes($incident->getRawOriginal());
    }

    public static function ofAttributes(array $row): string
    {
        $attributes = array_intersect_key($row, array_flip(self::FIELDS));
        ksort($attributes);

        return hash_hmac('sha256', 'incident-v1|'.json_encode($attributes, JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    }

    public static function ensureMatches(FallIncident $incident, string $version): void
    {
        if (! hash_equals(self::of($incident), $version)) {
            throw new IncidentConflictException;
        }
    }
}
