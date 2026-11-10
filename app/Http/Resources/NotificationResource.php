<?php

namespace App\Http\Resources;

use App\Support\StaffNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $payload = json_decode($row->payload, true);
        $valid = is_array($payload) && ($payload['schema_version'] ?? null) === 1
            && ($payload['message'] ?? null) === StaffNotification::MESSAGE && $row->valid_incident;
        $instant = fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');

        return ['id' => $row->id, 'kind' => 'fall_pending', 'message' => $valid ? StaffNotification::MESSAGE : 'Notifikasi insiden.',
            'created_at' => $instant($row->created_at), 'read_at' => $instant($row->read_at),
            'incident' => $valid ? ['status' => $row->status, 'is_verified' => $row->is_verified,
                'detail_url' => route('monitoring.incidents.show', $row->fall_incident_id, absolute: false)] : null];
    }
}
