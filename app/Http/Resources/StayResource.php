<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'started_at' => $this->started_at->toIso8601String(), 'ended_at' => $this->ended_at?->toIso8601String(),
            'room' => [...$this->room->only(['id', 'code', 'name', 'capacity', 'is_active']),
                'service_class' => $this->room->serviceClass->only(['id', 'code', 'name'])]];
    }
}
