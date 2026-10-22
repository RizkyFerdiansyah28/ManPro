<?php

namespace App\Http\Resources;

use App\Support\CanonicalServiceClasses;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'code', 'name', 'room_type', 'monthly_price', 'is_active',
            ...array_keys(CanonicalServiceClasses::DEFINITIONS['vip'])]),
            'is_canonical' => CanonicalServiceClasses::matches($this->resource),
            // Metadata aturan, bukan status integrasi AI atau grant entitlement runtime.
            'staff_ai_monitoring' => true,
            'approved_benefits' => CanonicalServiceClasses::DEFINITIONS[$this->code] ?? null,
            'rooms_count' => (int) $this->rooms_count, 'active_rooms_count' => (int) $this->active_rooms_count,
            'active_stays_count' => (int) $this->active_stays_count,
            'active_enrollments_count' => (int) $this->active_enrollments_count, 'cameras_count' => (int) $this->cameras_count];
    }
}
