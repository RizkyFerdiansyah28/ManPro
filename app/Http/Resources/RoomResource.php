<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'code', 'name', 'capacity', 'is_active']),
            'service_class' => $this->serviceClass->only(['id', 'code', 'name', 'room_type', 'is_active']),
            'active_occupancy' => (int) $this->active_stays_count,
            'has_stay_history' => $this->stays_count > 0, 'has_camera' => $this->camera_count > 0];
    }
}
