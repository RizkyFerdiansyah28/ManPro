<?php

namespace App\Http\Resources;

use App\Support\CanonicalServiceClasses;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyResidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $instant = fn ($v) => $v === null ? null : CarbonImmutable::parse($v)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $service = ['state' => $r->service_state, 'stay' => null, 'enrollment' => null, 'catalog_benefits' => null];
        if (in_array($r->service_state, ['staying', 'enrollment_only'], true)) {
            $service['enrollment'] = ['started_at' => $instant($r->enrollment_started_at), 'monthly_price' => $r->monthly_price,
                'service_class' => ['code' => $r->class_code, 'name' => $r->class_name, 'room_type' => $r->room_type]];
            // Katalog saja, bukan grant atau tautan fitur premium.
            $service['catalog_benefits'] = CanonicalServiceClasses::DEFINITIONS[$r->class_code];
            if ($r->service_state === 'staying') {
                $service['stay'] = ['room_code' => $r->room_code, 'room_name' => $r->room_name,
                    'started_at' => $instant($r->stay_started_at)];
            }
        }

        return ['id' => $r->id, 'name' => $r->name, 'birth_date' => $r->birth_date,
            'age' => (int) CarbonImmutable::parse($r->birth_date, 'Asia/Jakarta')->diffInYears(CarbonImmutable::parse($r->as_of)->setTimezone('Asia/Jakarta')->startOfDay()),
            'is_active' => $r->is_active, 'service' => $service];
    }
}
