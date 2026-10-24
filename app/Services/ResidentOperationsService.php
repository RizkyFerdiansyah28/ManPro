<?php

namespace App\Services;

use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Rules\MonthlyPrice;
use App\Support\CanonicalServiceClasses;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ResidentOperationsService
{
    public function enroll(User $actor, Elderly $target, string $classId): ServiceEnrollment
    {
        $observed = $target->activeStay()->first();

        return $this->locked($actor, $target, $classId, $observed?->room_id,
            function (User $admin, Elderly $elderly, $classes, ?Room $room) use ($classId, $observed) {
                Gate::forUser($admin)->authorize('create', [ServiceEnrollment::class, $elderly]);
                $this->activeResident($elderly);
                $stay = $elderly->activeStay()->first();
                $this->unchangedStay($observed, $stay);
                if ($elderly->activeServiceEnrollment()->exists()) {
                    $this->reject('enrollment', 'Lansia sudah memiliki enrollment aktif.');
                }
                $class = $classes->get($classId);
                $this->activeClass($class);
                if ($stay && $room->service_class_id !== $class->id) {
                    $this->reject('service_class_id', 'Kelas enrollment harus sesuai dengan kamar pada masa tinggal aktif.');
                }
                if ($class->monthly_price === null) {
                    $this->reject('service_class_id', 'Tarif kelas belum diatur. Atur tarif sebelum membuat enrollment.');
                }
                $price = MonthlyPrice::normalize($class->monthly_price);
                $started = CarbonImmutable::now()->startOfSecond();
                $this->noFutureHistory($elderly->serviceEnrollments(), $started);
                $enrollment = new ServiceEnrollment;
                $enrollment->elderly_id = $elderly->id;
                $enrollment->service_class_id = $class->id;
                $enrollment->monthly_price = $price;
                $enrollment->started_at = $started;
                $enrollment->save();

                return $enrollment;
            });
    }

    public function startStay(User $actor, Elderly $target, string $roomId, string $enrollmentId): ElderlyStay
    {
        return $this->locked($actor, $target, null, $roomId,
            function (User $admin, Elderly $elderly, $classes, Room $room) use ($enrollmentId) {
                Gate::forUser($admin)->authorize('create', [ElderlyStay::class, $elderly]);
                $this->activeResident($elderly);
                $class = $classes->get($room->service_class_id);
                $this->activeClass($class);
                if (! $room->is_active) {
                    $this->reject('room_id', 'Kamar tidak aktif.');
                }
                if ($elderly->activeStay()->exists()) {
                    $this->reject('stay', 'Lansia sudah memiliki masa tinggal aktif.');
                }
                $enrollment = $elderly->activeServiceEnrollment()->lockForUpdate()->first();
                if (! $enrollment || $enrollment->id !== $enrollmentId) {
                    $this->reject('enrollment_id', 'Enrollment aktif berubah atau tidak tersedia. Muat ulang halaman.');
                }
                if ($enrollment->service_class_id !== $class->id) {
                    $this->reject('room_id', 'Kelas kamar harus sama dengan enrollment aktif.');
                }
                if ($room->capacity < 1 || ($class->room_type === 'private' && $room->capacity !== 1)) {
                    $this->reject('room_id', 'Kapasitas kamar tidak sesuai aturan kelas.');
                }
                // Statement baru setelah lock: wajib melihat hasil transaksi penempatan sebelumnya.
                if ($room->activeStays()->count() >= $room->capacity) {
                    $this->reject('room_id', 'Kamar sudah penuh. Pilih kamar lain yang sesuai.');
                }
                $started = CarbonImmutable::now()->startOfSecond();
                if ($enrollment->started_at->greaterThan($started)) {
                    $this->reject('enrollment_id', 'Enrollment belum mulai berlaku.');
                }
                $this->noFutureHistory($elderly->stays(), $started);
                $stay = new ElderlyStay;
                $stay->elderly_id = $elderly->id;
                $stay->room_id = $room->id;
                $stay->started_at = $started;
                $stay->save();

                return $stay;
            });
    }

    public function endStay(User $actor, Elderly $target, ElderlyStay $targetStay): void
    {
        abort_unless($targetStay->elderly_id === $target->id, 404);
        $this->locked($actor, $target, null, $targetStay->room_id,
            function (User $admin, Elderly $elderly) use ($targetStay) {
                $stay = $elderly->stays()->whereKey($targetStay->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($admin)->authorize('close', [$stay, $elderly]);
                if ($stay->room_id !== $targetStay->room_id) {
                    $this->reject('operation', 'Referensi kamar berubah. Muat ulang halaman.');
                }
                $this->closeRecord($stay);
            });
    }

    public function endEnrollment(User $actor, Elderly $target, ServiceEnrollment $targetEnrollment): void
    {
        abort_unless($targetEnrollment->elderly_id === $target->id, 404);
        $observed = $target->activeStay()->first();
        $this->locked($actor, $target, $targetEnrollment->service_class_id, $observed?->room_id,
            function (User $admin, Elderly $elderly) use ($targetEnrollment, $observed) {
                $enrollment = $elderly->serviceEnrollments()->whereKey($targetEnrollment->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($admin)->authorize('close', [$enrollment, $elderly]);
                if ($enrollment->service_class_id !== $targetEnrollment->service_class_id) {
                    $this->reject('operation', 'Referensi kelas berubah. Muat ulang halaman.');
                }
                $stay = $elderly->activeStay()->first();
                $this->unchangedStay($observed, $stay);
                if ($stay) {
                    $this->reject('operation', 'Tutup masa tinggal aktif terlebih dahulu. Enrollment tidak ditutup otomatis.');
                }
                $this->closeRecord($enrollment);
            });
    }

    private function locked(User $actor, Elderly $target, ?string $classId, ?string $roomId, Closure $operation): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $target, $classId, $roomId, $operation) {
                // READ COMMITTED memberi snapshot baru untuk count setelah penantian row lock.
                if (DB::connection()->getDriverName() !== 'pgsql'
                    || DB::selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
                    throw new RuntimeException('Operasi penempatan memerlukan PostgreSQL READ COMMITTED.');
                }
                $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless($admin->isActiveAdmin(), 403);
                $observedRoom = $roomId ? Room::findOrFail($roomId) : null;
                $classes = ServiceClass::whereIn('id', array_filter([$classId, $observedRoom?->service_class_id]))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $room = $roomId ? Room::whereKey($roomId)->lockForUpdate()->firstOrFail() : null;
                if ($room && $room->service_class_id !== $observedRoom->service_class_id) {
                    $this->reject('room_id', 'Kelas kamar berubah. Muat ulang halaman.');
                }
                $elderly = Elderly::whereKey($target->id)->lockForUpdate()->firstOrFail();

                return $operation($admin, $elderly, $classes, $room);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                foreach (['elderly_stays_one_active', 'service_enrollments_one_active'] as $constraint) {
                    if (str_contains($exception->errorInfo[2] ?? '', '"'.$constraint.'"')) {
                        $this->reject('operation', 'Lansia sudah memiliki record operasional aktif. Muat ulang halaman.');
                    }
                }
            }
            throw $exception;
        }
    }

    private function activeResident(Elderly $elderly): void
    {
        if (! $elderly->is_active) {
            $this->reject('resident', 'Lansia nonaktif tidak dapat memulai masa tinggal atau enrollment.');
        }
    }

    private function activeClass(?ServiceClass $class): void
    {
        if (! $class || ! $class->is_active) {
            $this->reject('service_class_id', 'Kelas layanan tidak aktif atau tidak tersedia.');
        }
        CanonicalServiceClasses::ensureMatches($class);
    }

    private function unchangedStay(?ElderlyStay $observed, ?ElderlyStay $actual): void
    {
        if ($observed?->id !== $actual?->id || $observed?->room_id !== $actual?->room_id) {
            $this->reject('operation', 'Masa tinggal berubah. Muat ulang halaman sebelum melanjutkan.');
        }
    }

    private function closeRecord(Model $record): void
    {
        if ($record->ended_at !== null) {
            $this->reject('operation', 'Record ini sudah ditutup. Riwayat tidak diubah.');
        }
        $ended = CarbonImmutable::now()->startOfSecond();
        if (! $ended->greaterThan($record->started_at)) {
            $this->reject('operation', 'Waktu akhir harus setelah waktu mulai. Coba kembali setelah waktu mulai terlewati.');
        }
        $record->ended_at = $ended;
        $record->save();
    }

    private function noFutureHistory($relation, CarbonImmutable $started): void
    {
        if ($relation->where('ended_at', '>', $started)->exists()) {
            $this->reject('operation', 'Riwayat memiliki waktu akhir di masa depan. Perlu pemeriksaan operator.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
