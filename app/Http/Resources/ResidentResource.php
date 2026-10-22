<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'name' => $this->name, 'birth_date' => $this->birth_date->format('Y-m-d'),
            'age' => $this->age, 'is_active' => $this->is_active];
        if ($request->user()->isActiveAdmin() && $this->relationLoaded('family')) {
            $data['family'] = $this->family->only(['id', 'name', 'email', 'is_active']);
        }

        return $data;
    }
}
