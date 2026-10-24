<?php

namespace App\Services;

use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\StayResource;
use App\Models\Elderly;
use Illuminate\Http\Request;

class ResidentOperationsReadService
{
    public function summary(Elderly $elderly, Request $request): array
    {
        $elderly->load(['activeStay.room.serviceClass:id,code,name', 'activeServiceEnrollment.serviceClass:id,code,name,is_active']);

        return ['stay' => $elderly->activeStay ? (new StayResource($elderly->activeStay))->resolve($request) : null,
            'enrollment' => $elderly->activeServiceEnrollment ? (new EnrollmentResource($elderly->activeServiceEnrollment))->resolve($request) : null];
    }
}
