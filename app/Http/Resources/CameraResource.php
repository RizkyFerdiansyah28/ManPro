<?php

namespace App\Http\Resources;

use App\Support\CameraVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CameraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->only(['id', 'name', 'source_type', 'is_active']),
            'room' => [...$this->room->only(['id', 'code', 'name', 'is_active']),
                'service_class' => $this->room->serviceClass->only(['id', 'code', 'name', 'is_active'])],
            'has_source_reference' => $this->resource->getRawOriginal('source_reference') !== null,
            'detections_count' => (int) $this->ai_detections_count,
            'version' => CameraVersion::of($this->resource)];
    }
}
