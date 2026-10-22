<?php

namespace App\Services;

use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use App\Support\CanonicalServiceClasses;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RoomService
{
    public function create(User $actor, array $data): Room
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($admin)->authorize('create', Room::class);
                $class = ServiceClass::whereKey($data['service_class_id'])->lockForUpdate()->firstOrFail();
                $this->requireActiveClass($class);
                $this->checkCapacity($class, (int) $data['capacity'], 0);
                $room = new Room;
                $room->service_class_id = $class->id;
                $room->code = $data['code'];
                $room->name = $data['name'];
                $room->capacity = (int) $data['capacity'];
                $room->is_active = true;
                $room->save();

                return $room;
            });
        } catch (QueryException $exception) {
            $this->handleDuplicateCode($exception);
        }
    }

    public function setActive(User $actor, Room $target, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $active) {
            $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $class = ServiceClass::whereKey($target->service_class_id)->lockForUpdate()->firstOrFail();
            $room = Room::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('updateStatus', $room);
            $this->requireCurrentClass($room, $class->id);
            if ($active) {
                $this->requireActiveClass($class);
                $this->checkCapacity($class, $room->capacity, $room->activeStays()->count());
            } else {
                if ($room->activeStays()->exists()) {
                    throw ValidationException::withMessages(['status' => 'Kamar masih memiliki masa tinggal aktif. Penonaktifan ditolak tanpa mengakhiri masa tinggal.']);
                }
                if ($room->camera()->exists()) {
                    throw ValidationException::withMessages(['status' => 'Kamar masih memiliki kamera tertaut. Penonaktifan ditolak; kamera dan konfigurasinya tetap tersimpan.']);
                }
            }
            $room->is_active = $active;
            $room->save();
        });
    }

    public function update(User $actor, Room $target, array $data): void
    {
        try {
            DB::transaction(function () use ($actor, $target, $data) {
                $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                // Kunci kelas asal/tujuan menurut UUID sebelum kamar, konsisten dengan deactivation kelas.
                $classes = ServiceClass::whereIn('id', [$data['current_service_class_id'], $data['service_class_id']])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $room = Room::whereKey($target->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($admin)->authorize('update', $room);
                $this->requireCurrentClass($room, $data['current_service_class_id']);
                $class = $classes->get($data['service_class_id']);
                if (! $class) {
                    throw ValidationException::withMessages(['service_class_id' => 'Kelas layanan tidak ditemukan.']);
                }
                $changingClass = $room->service_class_id !== $class->id;
                if ($changingClass) {
                    if (! filter_var($data['confirm_class_change'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        throw ValidationException::withMessages(['confirm_class_change' => 'Konfirmasikan perubahan kelas kamar.']);
                    }
                    $this->requireActiveClass($class);
                    if ($room->stays()->exists() || $room->camera()->exists()) {
                        throw ValidationException::withMessages(['service_class_id' => 'Kelas kamar tidak dapat diubah karena kamar memiliki riwayat masa tinggal atau kamera tertaut.']);
                    }
                }
                $this->checkCapacity($class, (int) $data['capacity'], $room->activeStays()->count());
                $room->code = $data['code'];
                $room->name = $data['name'];
                $room->capacity = (int) $data['capacity'];
                $room->service_class_id = $class->id;
                $room->save();
            });
        } catch (QueryException $exception) {
            $this->handleDuplicateCode($exception);
        }
    }

    private function checkCapacity(ServiceClass $class, int $capacity, int $occupancy): void
    {
        CanonicalServiceClasses::ensureMatches($class);
        if ($capacity < 1 || $capacity > 2147483647) {
            throw ValidationException::withMessages(['capacity' => 'Kapasitas harus positif dan sesuai tipe integer database.']);
        }
        if ($class->room_type === 'private' && $capacity !== 1) {
            throw ValidationException::withMessages(['capacity' => 'Kamar VIP/pribadi harus memiliki kapasitas tepat 1.']);
        }
        if ($capacity < $occupancy) {
            throw ValidationException::withMessages(['capacity' => 'Kapasitas tidak boleh lebih kecil dari jumlah penghuni aktif saat ini.']);
        }
    }

    private function requireActiveClass(ServiceClass $class): void
    {
        CanonicalServiceClasses::ensureMatches($class);
        if (! $class->is_active) {
            throw ValidationException::withMessages(['service_class_id' => 'Pilih kelas layanan yang aktif.']);
        }
    }

    private function requireCurrentClass(Room $room, string $expected): void
    {
        if ($room->service_class_id !== $expected) {
            throw ValidationException::withMessages(['service_class_id' => 'Kelas kamar telah berubah. Muat ulang halaman sebelum melanjutkan.']);
        }
    }

    private function handleDuplicateCode(QueryException $exception): never
    {
        if (($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->errorInfo[2] ?? '', '"rooms_code_unique"')) {
            throw ValidationException::withMessages(['code' => 'Kode kamar sudah digunakan.']);
        }
        throw $exception;
    }
}
