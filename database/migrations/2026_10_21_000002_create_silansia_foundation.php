<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Fondasi Silansia memerlukan PostgreSQL.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('keluarga')->index();
            $table->boolean('is_active')->default(false)->index();
        });

        Schema::create('service_classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('room_type', 20);
            $table->decimal('monthly_price', 14, 2)->nullable();
            $table->boolean('family_live_cctv')->default(false);
            $table->boolean('instant_verified_emergency')->default(false);
            $table->boolean('daily_gait_report')->default(false);
            $table->boolean('weekly_gait_report')->default(false);
            $table->boolean('monthly_gait_report')->default(false);
            $table->boolean('verified_incident_summary')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('elderlies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->date('birth_date');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->index('family_id');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_class_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->integer('capacity');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->index('service_class_id');
        });

        Schema::create('service_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('elderly_id')->constrained('elderlies')->restrictOnDelete();
            $table->foreignUuid('service_class_id')->constrained()->restrictOnDelete();
            $table->decimal('monthly_price', 14, 2)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->index(['elderly_id', 'started_at']);
            $table->index('service_class_id');
            $table->unique(['id', 'elderly_id']);
        });

        Schema::create('elderly_stays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('elderly_id')->constrained('elderlies')->restrictOnDelete();
            $table->foreignUuid('room_id')->constrained()->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->index(['elderly_id', 'started_at']);
            $table->index(['room_id', 'ended_at']);
        });

        Schema::create('cameras', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('source_type', 20);
            $table->text('source_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('ai_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('camera_id')->constrained()->restrictOnDelete();
            $table->string('external_event_id')->unique();
            $table->string('event_type', 20);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->decimal('confidence', 6, 5)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['camera_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('gait_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_detection_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('elderly_id')->nullable()->constrained('elderlies')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('identity_basis', 40)->default('unknown');
            $table->timestampsTz();
            $table->index(['elderly_id', 'created_at']);
        });

        Schema::create('fall_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_detection_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('elderly_id')->nullable()->constrained('elderlies')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('responding_caregiver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index('elderly_id');
            $table->index('responding_caregiver_id');
            $table->index('verified_by');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->foreign('notifiable_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUuid('fall_incident_id')->nullable()->constrained()->restrictOnDelete();
            // JSON text follows Laravel's native database notification contract.
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index('fall_incident_id');
            $table->index(['notifiable_id', 'read_at', 'created_at'], 'notifications_recipient_read_index');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 80)->unique();
            $table->foreignUuid('elderly_id')->constrained('elderlies')->restrictOnDelete();
            $table->uuid('service_enrollment_id');
            $table->foreign(['service_enrollment_id', 'elderly_id'], 'invoices_enrollment_elderly_foreign')
                ->references(['id', 'elderly_id'])->on('service_enrollments')->restrictOnDelete();
            $table->date('billing_month');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('IDR');
            $table->date('due_date');
            $table->date('grace_period_ends_on');
            $table->string('status', 20)->default('unpaid');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['elderly_id', 'billing_month']);
            $table->index('service_enrollment_id');
            $table->index(['status', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('submitted_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->text('proof_path');
            $table->timestampTz('submitted_at')->useCurrent();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestampsTz();
            $table->index(['invoice_id', 'status']);
            $table->index('submitted_by');
            $table->index('verified_by');
        });

        $checks = [
            'users' => ["role IN ('admin', 'perawat', 'keluarga')"],
            'service_classes' => [
                "code IN ('vip', 'class_2', 'class_3')",
                "(code = 'vip' AND room_type = 'private') OR (code IN ('class_2', 'class_3') AND room_type = 'shared')",
                'monthly_price IS NULL OR monthly_price >= 0',
            ],
            'rooms' => ['capacity > 0'],
            'service_enrollments' => ['ended_at IS NULL OR ended_at > started_at', 'monthly_price IS NULL OR monthly_price >= 0'],
            'elderly_stays' => ['ended_at IS NULL OR ended_at > started_at'],
            'cameras' => ["source_type IN ('webcam', 'uploaded_video', 'rtsp')"],
            'ai_detections' => ["event_type IN ('gait', 'fall')", 'confidence IS NULL OR confidence BETWEEN 0 AND 1', 'length(trim(external_event_id)) > 0'],
            'gait_observations' => [
                "category IN ('walking_normal', 'walking_abnormal', 'no_walking_detected')",
                "(elderly_id IS NULL AND identity_basis = 'unknown') OR (elderly_id IS NOT NULL AND identity_basis = 'vip_room_single_resident')",
            ],
            'fall_incidents' => [
                "status IN ('pending', 'acknowledged', 'resolved', 'false_alarm')",
                '(verified_at IS NULL) = (verified_by IS NULL)',
                'elderly_id IS NULL OR verified_at IS NOT NULL',
                "status <> 'pending' OR (elderly_id IS NULL AND responding_caregiver_id IS NULL AND acknowledged_at IS NULL AND verified_at IS NULL AND resolved_at IS NULL)",
                "status = 'pending' OR (responding_caregiver_id IS NOT NULL AND acknowledged_at IS NOT NULL)",
                "(status IN ('resolved', 'false_alarm')) = (resolved_at IS NOT NULL)",
                "status <> 'resolved' OR verified_at IS NOT NULL",
                "status <> 'false_alarm' OR (elderly_id IS NULL AND verified_at IS NULL)",
                'verified_at IS NULL OR verified_at >= acknowledged_at',
                'resolved_at IS NULL OR resolved_at >= acknowledged_at',
                'resolved_at IS NULL OR verified_at IS NULL OR resolved_at >= verified_at',
            ],
            'notifications' => ["notifiable_type = 'App\\Models\\User'"],
            'invoices' => [
                'amount >= 0', "currency = 'IDR'", 'EXTRACT(DAY FROM billing_month) = 1',
                'grace_period_ends_on = due_date + 3', "status IN ('unpaid', 'paid', 'cancelled')",
                "(status = 'paid') = (paid_at IS NOT NULL)",
            ],
            'payments' => [
                'amount >= 0', "status IN ('pending', 'verified', 'rejected')",
                "(status = 'pending' AND verified_by IS NULL AND verified_at IS NULL) OR (status IN ('verified', 'rejected') AND verified_by IS NOT NULL AND verified_at IS NOT NULL)",
                'verified_at IS NULL OR verified_at >= submitted_at',
            ],
        ];

        foreach ($checks as $table => $expressions) {
            foreach ($expressions as $index => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_check_{$index} CHECK ({$expression})");
            }
        }

        DB::statement('CREATE UNIQUE INDEX elderly_stays_one_active ON elderly_stays (elderly_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX service_enrollments_one_active ON service_enrollments (elderly_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        foreach (['payments', 'invoices', 'notifications', 'fall_incidents', 'gait_observations', 'ai_detections', 'cameras', 'elderly_stays', 'service_enrollments', 'rooms', 'elderlies', 'service_classes'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_check_0');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
