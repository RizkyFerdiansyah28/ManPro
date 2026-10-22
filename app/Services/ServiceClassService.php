<?php

namespace App\Services;

use App\Models\ServiceClass;
use App\Models\User;
use App\Rules\MonthlyPrice;
use App\Support\CanonicalServiceClasses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ServiceClassService
{
    public function update(User $actor, ServiceClass $target, array $data): void
    {
        DB::transaction(function () use ($actor, $target, $data) {
            $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $class = ServiceClass::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('update', $class);
            CanonicalServiceClasses::ensureMatches($class);
            $class->name = $data['name'];
            $class->monthly_price = MonthlyPrice::normalize($data['monthly_price']);
            $class->save();
        });
    }

    public function setActive(User $actor, ServiceClass $target, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $active) {
            $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $class = ServiceClass::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('updateStatus', $class);
            CanonicalServiceClasses::ensureMatches($class);
            // Urutan: user -> kelas -> kamar. RoomService memakai urutan yang sama.
            // Lock kamar juga menahan penambahan stay/kamera melalui FK saat pengecekan.
            $class->rooms()->orderBy('id')->lockForUpdate()->get(['id']);
            if (! $active) {
                $message = match (true) {
                    $class->rooms()->where('is_active', true)->exists() => 'Kelas masih memiliki kamar aktif.',
                    $class->stays()->whereNull('ended_at')->exists() => 'Kelas masih memiliki masa tinggal aktif.',
                    $class->serviceEnrollments()->whereNull('ended_at')->exists() => 'Kelas masih memiliki enrollment aktif.',
                    $class->cameras()->exists() => 'Kelas masih memiliki kamera tertaut pada kamarnya.',
                    default => null,
                };
                if ($message !== null) {
                    throw ValidationException::withMessages(['status' => $message.' Penonaktifan ditolak; tidak ada dependensi yang diubah otomatis.']);
                }
            }
            $class->is_active = $active;
            $class->save();
        });
    }
}
