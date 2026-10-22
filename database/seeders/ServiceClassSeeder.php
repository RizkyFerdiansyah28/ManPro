<?php

namespace Database\Seeders;

use App\Models\ServiceClass;
use App\Support\CanonicalServiceClasses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceClassSeeder extends Seeder
{
    public function run(): void
    {
        // Hanya kelas yang belum ada dibuat. Sinkronisasi benefit existing memerlukan persetujuan terpisah.
        DB::transaction(function () {
            foreach (['vip' => 'VIP / Kelas 1', 'class_2' => 'Kelas 2', 'class_3' => 'Kelas 3'] as $code => $name) {
                ServiceClass::firstOrCreate(['code' => $code], [
                    'name' => $name,
                    ...CanonicalServiceClasses::DEFINITIONS[$code],
                    'monthly_price' => null,
                    'is_active' => true,
                ]);
            }
        });
    }
}
