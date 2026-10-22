<?php

namespace App\Services;

use App\Models\Elderly;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ResidentService
{
    public function create(User $actor, array $data): Elderly
    {
        return DB::transaction(function () use ($actor, $data) {
            [$admin, $family] = $this->lockUsers($actor, $data['family_id']);
            Gate::forUser($admin)->authorize('create', Elderly::class);
            $this->requireEligibleFamily($family);
            $elderly = new Elderly;
            $elderly->name = $data['name'];
            $elderly->birth_date = $data['birth_date'];
            $elderly->family_id = $family->id;
            $elderly->is_active = true;
            $elderly->save();

            return $elderly;
        });
    }

    public function update(User $actor, Elderly $target, array $data): void
    {
        DB::transaction(function () use ($actor, $target, $data) {
            [$admin] = $this->lockUsers($actor);
            $elderly = Elderly::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('update', $elderly);
            $elderly->name = $data['name'];
            $elderly->birth_date = $data['birth_date'];
            $elderly->save();
        });
    }

    public function reassignFamily(User $actor, Elderly $target, array $data): void
    {
        DB::transaction(function () use ($actor, $target, $data) {
            [$admin, $family] = $this->lockUsers($actor, $data['family_id']);
            $elderly = Elderly::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('reassignFamily', $elderly);
            if (! filter_var($data['confirm_reassignment'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['confirm_reassignment' => 'Konfirmasikan perubahan keluarga terlebih dahulu.']);
            }
            if ($elderly->family_id !== $data['current_family_id']) {
                throw ValidationException::withMessages(['family_id' => 'Keluarga telah berubah. Muat ulang halaman sebelum melanjutkan.']);
            }
            $this->requireEligibleFamily($family);
            if ($elderly->family_id === $family->id) {
                throw ValidationException::withMessages(['family_id' => 'Pilih keluarga yang berbeda dari keluarga saat ini.']);
            }
            $elderly->family_id = $family->id;
            $elderly->save();
        });
    }

    public function setActive(User $actor, Elderly $target, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $active) {
            [$admin] = $this->lockUsers($actor);
            $elderly = Elderly::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('updateStatus', $elderly);
            // Keputusan Phase 3A: tidak mengakhiri penempatan/enrollment secara otomatis.
            if (! $active && ($elderly->activeStay()->exists() || $elderly->activeServiceEnrollment()->exists())) {
                throw ValidationException::withMessages(['status' => 'Lansia masih memiliki masa tinggal atau enrollment aktif. Penonaktifan ditolak sampai relasi tersebut ditutup melalui fase yang sesuai.']);
            }
            $elderly->is_active = $active;
            $elderly->save();
        });
    }

    private function lockUsers(User $actor, ?string $familyId = null): array
    {
        // Urutan konsisten dengan AccountService; eligibility dicek ulang setelah lock.
        $users = User::whereIn('id', array_filter([$actor->id, $familyId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$users->get($actor->id) ?? abort(403), $familyId ? $users->get($familyId) : null];
    }

    private function requireEligibleFamily(?User $family): void
    {
        if (! $family || $family->role !== 'keluarga' || ! $family->is_active) {
            throw ValidationException::withMessages(['family_id' => 'Pilih akun keluarga yang aktif.']);
        }
    }
}
