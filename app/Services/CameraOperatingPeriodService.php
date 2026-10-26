<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\CameraOperatingPeriod;
use App\Models\Room;
use App\Models\ServiceClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CameraOperatingPeriodService
{
    public static function serverTime(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant')->instant)->utc();
    }

    public function open(Camera $camera, Room $room, ServiceClass $class): void
    {
        $this->requireTransaction();
        if ($camera->room_id !== $room->id || $room->service_class_id !== $class->id) {
            throw new LogicException('Asosiasi periode kamera tidak konsisten.');
        }
        if ($camera->operatingPeriods()->whereNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['status' => 'Kamera masih memiliki periode terbuka. Muat ulang konfigurasi.']);
        }
        $period = new CameraOperatingPeriod;
        $period->camera_id = $camera->id;
        $period->room_id = $room->id;
        $period->service_class_id = $class->id;
        $period->room_type = $class->room_type;
        $period->room_capacity = $room->capacity;
        $period->source_type = $camera->source_type;
        $period->opened_at = self::serverTime();
        $period->save();
    }

    public function close(Camera $camera): void
    {
        $this->requireTransaction();
        $period = $camera->operatingPeriods()->whereNull('closed_at')->lockForUpdate()->first();
        if ($period) {
            $time = self::serverTime();
            if (! $time->greaterThan($period->opened_at)) {
                throw ValidationException::withMessages(['status' => 'Waktu server belum melewati pembukaan periode. Coba kembali.']);
            }
            $period->closed_at = $time;
            $period->save();
        }
        // Kamera legacy tanpa periode tidak di-backfill.
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Periode kamera wajib ditulis dalam transaksi lifecycle yang mengunci kamera.');
        }
    }
}
