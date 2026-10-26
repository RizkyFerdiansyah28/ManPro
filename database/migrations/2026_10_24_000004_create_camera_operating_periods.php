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
        Schema::create('camera_operating_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('camera_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('room_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('service_class_id')->constrained()->restrictOnDelete();
            $table->string('room_type', 20);
            $table->integer('room_capacity');
            $table->string('source_type', 20);
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['id', 'camera_id'], 'camera_periods_id_camera_unique');
            $table->index(['room_id', 'opened_at'], 'camera_periods_room_opened_index');
            $table->index(['camera_id', 'opened_at'], 'camera_periods_camera_opened_index');
            $table->index('service_class_id', 'camera_periods_class_index');
        });
        DB::statement('ALTER TABLE camera_operating_periods ADD CONSTRAINT camera_periods_time_check CHECK (closed_at IS NULL OR closed_at > opened_at)');
        DB::statement("ALTER TABLE camera_operating_periods ADD CONSTRAINT camera_periods_room_check CHECK (room_type IN ('private','shared') AND room_capacity > 0 AND (room_type <> 'private' OR room_capacity = 1))");
        DB::statement("ALTER TABLE camera_operating_periods ADD CONSTRAINT camera_periods_source_check CHECK (source_type IN ('webcam','uploaded_video','rtsp'))");
        DB::statement('CREATE UNIQUE INDEX camera_periods_one_open ON camera_operating_periods (camera_id) WHERE closed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_operating_periods');
    }
};
