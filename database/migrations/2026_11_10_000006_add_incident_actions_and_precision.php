<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIMES = ['acknowledged_at', 'verified_at', 'resolved_at', 'created_at', 'updated_at'];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::create('fall_incident_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fall_incident_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->integer('sequence');
            $table->string('action', 20);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignUuid('elderly_id')->nullable()->constrained('elderlies')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->char('request_fingerprint', 64);
            $table->timestampTz('acted_at', 6);
            $table->unique(['actor_id', 'request_id'], 'incident_actions_actor_request_unique');
            $table->unique(['fall_incident_id', 'sequence'], 'incident_actions_sequence_unique');
            $table->index('elderly_id');
        });
        DB::statement("ALTER TABLE fall_incident_actions
            ADD CONSTRAINT incident_actions_sequence_check CHECK (sequence > 0),
            ADD CONSTRAINT incident_actions_transition_check CHECK (
                (action = 'acknowledge' AND from_status = 'pending' AND to_status = 'acknowledged')
                OR (action = 'verify' AND from_status = 'acknowledged' AND to_status = 'acknowledged')
                OR (action = 'resolve' AND from_status = 'acknowledged' AND to_status = 'resolved')
                OR (action = 'false_alarm' AND from_status = 'acknowledged' AND to_status = 'false_alarm')
            ),
            ADD CONSTRAINT incident_actions_identity_check CHECK (elderly_id IS NULL OR action = 'verify'),
            ADD CONSTRAINT incident_actions_fingerprint_check CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
            ADD CONSTRAINT incident_actions_notes_check CHECK (
                (notes IS NULL OR char_length(notes) <= 2000)
                AND (action = 'acknowledge' OR (notes IS NOT NULL AND notes ~ '[^[:space:]]'))
            )");
        $this->precision(6);
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('LOCK TABLE fall_incidents, fall_incident_actions IN ACCESS EXCLUSIVE MODE');
        $fractional = implode(' OR ', array_map(fn ($field) => "$field <> date_trunc('second', $field)", self::TIMES));
        if (DB::table('fall_incident_actions')->exists()
            || DB::table('fall_incidents')->whereRaw($fractional)->exists()) {
            throw new RuntimeException('Rollback ditolak karena jurnal atau presisi mikrodetik insiden harus dipertahankan. Gunakan perubahan maju.');
        }
        Schema::drop('fall_incident_actions');
        $this->precision(0);
    }

    private function precision(int $precision): void
    {
        DB::statement('ALTER TABLE fall_incidents '.implode(', ', array_map(
            fn ($field) => "ALTER COLUMN $field TYPE timestamptz($precision)", self::TIMES)));
    }
};
