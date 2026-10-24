<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'started_at' => $this->started_at->toIso8601String(), 'ended_at' => $this->ended_at?->toIso8601String(),
            'monthly_price' => $this->monthly_price, 'service_class' => $this->serviceClass->only(['id', 'code', 'name', 'is_active'])];
    }
}
