<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitoringRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $admin = $request->user()->isActiveAdmin();
        $instant = fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $identity = ['state' => $row->identity_name === null ? 'unknown' : 'known',
            'name' => $row->identity_name, 'basis' => $row->identity_basis];
        if ($admin && $row->identity_name !== null) {
            $identity['resident_url'] = route('admin.residents.show', $row->identity_elderly_id, absolute: false);
        }

        return [
            'id' => $row->id, 'event_type' => $row->event_type,
            'occurred_at' => $instant($row->occurred_at), 'received_at' => $instant($row->received_at),
            'camera' => ['id' => $row->camera_id, 'name' => $row->camera_name,
                ...($admin ? ['configuration_url' => route('admin.cameras.show', $row->camera_id, absolute: false)] : [])],
            'context' => ['state' => $row->location_state,
                'room' => $row->room_id === null ? null : ['id' => $row->room_id, 'code' => $row->room_code, 'name' => $row->room_name],
                'service_class' => $row->class_id === null ? null : ['id' => $row->class_id, 'code' => $row->class_code, 'name' => $row->class_name]],
            'identity' => $identity,
            'gait' => $row->has_gait ? ['category' => $row->gait_category] : null,
            'fall' => $row->has_incident ? ['status' => $row->fall_status, 'is_verified' => $row->is_verified,
                'acknowledged_at' => $instant($row->acknowledged_at), 'verified_at' => $instant($row->verified_at),
                'resolved_at' => $instant($row->resolved_at)] : null,
        ];
    }
}
