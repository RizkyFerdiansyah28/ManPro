<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::table('ai_detections', function (Blueprint $table) {
            $table->uuid('camera_period_id')->nullable();
            $table->foreign(['camera_period_id', 'camera_id'], 'ai_detections_period_camera_foreign')
                ->references(['id', 'camera_id'])->on('camera_operating_periods')->restrictOnDelete();
            $table->index(['camera_period_id', 'occurred_at'], 'ai_detections_period_occurred_index');
        });
        DB::statement('ALTER TABLE ai_detections ALTER COLUMN occurred_at TYPE timestamptz(6), ALTER COLUMN received_at TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        // Jangan diam-diam membulatkan histori mikrodetik saat rollback.
        DB::statement('LOCK TABLE ai_detections IN ACCESS EXCLUSIVE MODE');
        if (DB::table('ai_detections')->whereNotNull('camera_period_id')->exists()
            || DB::table('ai_detections')->whereRaw("occurred_at <> date_trunc('second', occurred_at) OR received_at <> date_trunc('second', received_at)")->exists()) {
            throw new RuntimeException('Rollback ditolak karena deteksi memiliki periode atau presisi mikrodetik. Gunakan perubahan maju.');
        }
        Schema::table('ai_detections', function (Blueprint $table) {
            $table->dropForeign('ai_detections_period_camera_foreign');
            $table->dropIndex('ai_detections_period_occurred_index');
            $table->dropColumn('camera_period_id');
        });
        DB::statement('ALTER TABLE ai_detections ALTER COLUMN occurred_at TYPE timestamptz(0), ALTER COLUMN received_at TYPE timestamptz(0)');
    }
};
