<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $columns = [
        'service_classes' => 'monthly_price',
        'service_enrollments' => 'monthly_price',
        'invoices' => 'amount',
        'payments' => 'amount',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Proteksi nominal NaN memerlukan PostgreSQL.');
        }

        // DDL PostgreSQL transaksional: cegah penulisan di sela preflight dan ADD CHECK.
        DB::statement('LOCK TABLE service_classes, service_enrollments, invoices, payments IN SHARE ROW EXCLUSIVE MODE');
        foreach ($this->columns as $table => $column) {
            if (DB::table($table)->whereRaw("{$column} = 'NaN'::numeric")->exists()) {
                throw new RuntimeException('Ditemukan nominal NaN; migration dibatalkan tanpa mengubah data. Perlu koreksi nominal dengan persetujuan terpisah.');
            }
        }

        foreach ($this->columns as $table => $column) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_not_nan CHECK ({$column} IS NULL OR {$column} <> 'NaN'::numeric)");
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $table => $column) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_{$column}_not_nan");
        }
    }
};
