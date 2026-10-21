<?php

namespace Database\Seeders;

use App\Models\ServiceClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceClassSeeder extends Seeder
{
    public function run(): void
    {
        // Harga sengaja NULL. Rerun tidak menimpa harga/benefit yang sudah disunting admin.
        DB::transaction(function () {
            foreach ([
                ['vip', 'VIP / Kelas 1', 'private', true, true, true, false, false],
                ['class_2', 'Kelas 2', 'shared', false, true, true, false, false],
                ['class_3', 'Kelas 3', 'shared', false, false, false, true, true],
            ] as [$code, $name, $roomType, $cctv, $emergency, $daily, $weekly, $monthly]) {
                ServiceClass::firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'room_type' => $roomType,
                    'monthly_price' => null,
                    'family_live_cctv' => $cctv,
                    'instant_verified_emergency' => $emergency,
                    'daily_gait_report' => $daily,
                    'weekly_gait_report' => $weekly,
                    'monthly_gait_report' => $monthly,
                    'verified_incident_summary' => true,
                    'is_active' => true,
                ]);
            }
        });
    }
}
