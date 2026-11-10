<?php

namespace App\Http\Resources;

use App\Support\IncidentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function __construct($resource, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $verified = $row->verified_at !== null && $row->verified_by !== null;
        $instant = fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $data = ['id' => $row->id, 'status' => $row->status, 'is_verified' => $verified,
            'detail_url' => route('monitoring.incidents.show', $row->id, absolute: false),
            'occurred_at' => $instant($row->occurred_at), 'received_at' => $instant($row->received_at),
            'camera_name' => $row->camera_name, 'context' => ['state' => $row->location_state,
                'room_code' => $row->room_code, 'room_name' => $row->room_name, 'class_name' => $row->class_name],
            'identity_name' => $row->identity_name];
        if ($this->detail) {
            $canHandle = $request->user()->is_active && $request->user()->role === 'perawat';
            $allowed = $row->status === 'pending' ? ['acknowledge'] : ($row->status === 'acknowledged'
                ? ($verified ? ['resolve'] : ['verify', 'false_alarm']) : []);
            $data += ['acknowledged_at' => $instant($row->acknowledged_at), 'verified_at' => $instant($row->verified_at),
                'resolved_at' => $instant($row->resolved_at), 'responder_name' => $row->responder_name,
                'verifier_name' => $row->verifier_name, 'legacy_notes' => $row->notes,
                'version' => $canHandle ? IncidentVersion::ofAttributes((array) $row) : null,
                'allowed_actions' => $canHandle ? $allowed : []];
        }

        return $data;
    }
}
