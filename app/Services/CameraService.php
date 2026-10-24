<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use App\Support\CameraSource;
use App\Support\CameraVersion;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CameraService
{
    public function create(User $actor, #[\SensitiveParameter] array $data): Camera
    {
        return $this->locked($actor, [$data['room_id']], null,
            function ($admin, $rooms, $classes) use ($data) {
                Gate::forUser($admin)->authorize('create', Camera::class);
                $room = $rooms->get($data['room_id']);
                $this->eligibleRoom($room, $classes);
                if ($room->camera()->exists()) {
                    $this->reject('room_id', 'Kamar sudah memiliki kamera, termasuk kamera nonaktif.');
                }
                $source = CameraSource::validate($data['source_type'], $data['source_reference'] ?? null);
                $camera = new Camera;
                $camera->room_id = $room->id;
                $camera->name = $data['name'];
                $camera->source_type = $data['source_type'];
                $camera->source_reference = $source;
                $camera->is_active = false;
                $camera->save();

                return $camera->fresh();
            });
    }

    public function update(User $actor, Camera $target, #[\SensitiveParameter] array $data): void
    {
        $this->locked($actor, [$data['current_room_id'], $data['room_id']], $target,
            function ($admin, $rooms, $classes, Camera $camera) use ($data) {
                Gate::forUser($admin)->authorize('update', $camera);
                CameraVersion::ensureMatches($camera, $data['version']);
                if ($camera->room_id !== $data['current_room_id']) {
                    $this->reject('room_id', 'Kamar kamera sudah berubah. Muat ulang halaman.');
                }
                $room = $rooms->get($data['room_id']);
                $history = $camera->aiDetections()->exists();
                if ($room->id !== $camera->room_id) {
                    if (! filter_var($data['confirm_reassignment'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        $this->reject('confirm_reassignment', 'Konfirmasikan pemindahan kamera.');
                    }
                    if ($camera->is_active || $history) {
                        $this->reject('room_id', 'Pemindahan memerlukan kamera nonaktif tanpa riwayat deteksi.');
                    }
                    $this->eligibleRoom($room, $classes);
                    if ($room->camera()->whereKeyNot($camera->id)->exists()) {
                        $this->reject('room_id', 'Kamar tujuan sudah memiliki kamera.');
                    }
                }
                $changingType = $data['source_type'] !== $camera->source_type;
                $action = $data['source_action'];
                if (! in_array($action, ['keep', 'replace', 'clear'], true)) {
                    $this->reject('source_action', 'Tindakan sumber tidak valid.');
                }
                if ($changingType || $action !== 'keep') {
                    if ($camera->is_active) {
                        $this->reject('source_action', 'Nonaktifkan kamera sebelum mengubah konfigurasi sumber.');
                    }
                    if ($changingType && $history) {
                        $this->reject('source_type', 'Jenis sumber tidak dapat diubah setelah ada riwayat deteksi.');
                    }
                    if ($action === 'keep') {
                        $this->reject('source_action', 'Pilih ganti atau kosongkan sumber ketika mengubah jenisnya.');
                    }
                }
                if ($action === 'keep' && ($data['source_reference'] ?? null) !== null) {
                    $this->reject('source_reference', 'Pilih tindakan ganti untuk mengirim sumber baru.');
                }
                if ($action === 'clear' && ($data['source_reference'] ?? null) !== null) {
                    $this->reject('source_reference', 'Jangan kirim nilai sumber ketika memilih kosongkan.');
                }
                if ($action === 'replace') {
                    if (($data['source_reference'] ?? null) === null || $data['source_reference'] === '') {
                        $this->reject('source_reference', 'Isi sumber pengganti secara eksplisit.');
                    }
                    $camera->source_reference = CameraSource::validate($data['source_type'], $data['source_reference']);
                } elseif ($action === 'clear') {
                    $camera->source_reference = null;
                }
                if (! in_array($data['source_type'], CameraSource::TYPES, true)) {
                    $this->reject('source_type', 'Jenis sumber tidak didukung.');
                }
                $camera->name = $data['name'];
                $camera->room_id = $room->id;
                $camera->source_type = $data['source_type'];
                // Bandingkan ciphertext mentah agar sumber rusak tetap dapat diganti
                // tanpa mendekripsi nilai lama pada pemeriksaan dirty encrypted cast.
                $attributes = $camera->getAttributes();
                $changes = [];
                foreach (['name', 'room_id', 'source_type', 'source_reference'] as $field) {
                    if ($attributes[$field] !== $camera->getRawOriginal($field)) {
                        $changes[$field] = $attributes[$field];
                    }
                }
                if ($changes !== []) {
                    $changes['updated_at'] = $camera->freshTimestampString();
                    Camera::whereKey($camera->id)->toBase()->update($changes);
                }
            });
    }

    public function setActive(User $actor, Camera $target, string $version, bool $active): bool
    {
        return $this->locked($actor, [$target->room_id], $target,
            function ($admin, $rooms, $classes, Camera $camera) use ($target, $version, $active) {
                Gate::forUser($admin)->authorize('updateStatus', $camera);
                CameraVersion::ensureMatches($camera, $version);
                if ($camera->room_id !== $target->room_id) {
                    $this->reject('room_id', 'Kamar kamera berubah. Muat ulang halaman.');
                }
                if ($active) {
                    $this->eligibleRoom($rooms->get($camera->room_id), $classes);
                }
                if ($camera->is_active === $active) {
                    return false;
                }
                if ($active) {
                    try {
                        $source = $camera->source_reference;
                    } catch (DecryptException) {
                        $this->reject('source_reference', 'Sumber privat tidak dapat dibaca. Perbarui saat kamera nonaktif.');
                    }
                    CameraSource::validate($camera->source_type, $source, true);
                }
                $camera->is_active = $active;
                $camera->save();

                return true;
            });
    }

    private function locked(User $actor, array $roomIds, ?Camera $target, Closure $operation): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $roomIds, $target, $operation) {
                if (DB::connection()->getDriverName() !== 'pgsql' || DB::selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
                    throw new RuntimeException('Pengelolaan kamera memerlukan PostgreSQL READ COMMITTED.');
                }
                $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless($admin->isActiveAdmin(), 403);
                $roomIds = array_values(array_unique($roomIds));
                $observed = Room::whereIn('id', $roomIds)->orderBy('id')->get()->keyBy('id');
                if ($observed->count() !== count($roomIds)) {
                    $this->reject('room_id', 'Kamar tidak tersedia. Muat ulang halaman.');
                }
                $classes = ServiceClass::whereIn('id', $observed->pluck('service_class_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $rooms = Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($rooms as $room) {
                    if ($room->service_class_id !== $observed->get($room->id)->service_class_id) {
                        $this->reject('room_id', 'Kelas kamar berubah. Muat ulang halaman.');
                    }
                }
                if ($rooms->count() !== count($roomIds)) {
                    $this->reject('room_id', 'Kamar tidak tersedia.');
                }
                $camera = $target ? Camera::whereKey($target->id)->lockForUpdate()->firstOrFail() : null;

                return $operation($admin, $rooms, $classes, $camera);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505' && str_contains($exception->errorInfo[2] ?? '', '"cameras_room_id_unique"')) {
                $this->reject('room_id', 'Kamar sudah memiliki kamera.');
            }
            throw $exception;
        }
    }

    private function eligibleRoom(?Room $room, $classes): void
    {
        if (! $room || ! $room->is_active) {
            $this->reject('room_id', 'Pilih kamar aktif. Kamar tidak diaktifkan otomatis.');
        }
        if (! $classes->get($room->service_class_id)?->is_active) {
            $this->reject('room_id', 'Kelas kamar tidak aktif.');
        }
        // Tidak memeriksa CCTV/benefit keluarga: konfigurasi keselamatan berlaku bagi semua kelas.
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
