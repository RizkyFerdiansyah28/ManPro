<?php

namespace App\Services;

use App\Models\Elderly;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialOwnershipLedgerService
{
    private const COLUMNS = [
        'invoices' => 'billing_schema_version bill_to_family_id financial_ownership_period_id initial_issued_by initial_issued_at current_revision_id cancelled_by cancelled_at cancellation_reason',
        'payments' => 'billing_schema_version invoice_revision_id submission_channel proof_sha256 proof_size_bytes proof_mime proof_disk public_rejection_reason',
        'financial_ownership_periods' => 'id elderly_id family_id previous_period_id effective_from effective_to recorded_by recorded_at source',
        'invoice_revisions' => 'id invoice_id elderly_id service_enrollment_id revision_no previous_revision_id issued_by issued_at policy_version monthly_price effective_started_at effective_ended_at chargeable_days days_in_month amount currency due_date grace_period_ends_on reason_code reason content_hash',
        'billing_requests' => 'id actor_id request_id operation intent_hash invoice_id outcome_payment_id outcome_revision_id completed_at',
        'billing_actions' => 'id billing_request_id actor_id invoice_id ownership_period_id payment_id revision_id invoice_sequence action occurred_at reason_code private_note details',
    ];

    public function enabled(): bool
    {
        $enabled = config('financial_ownership.enabled', false);
        if (! is_bool($enabled)) {
            $this->reject('Konfigurasi aktivasi ledger tidak valid. Hubungi administrator sistem.');
        }

        return $enabled;
    }

    public function assertReady(): void
    {
        if (! $this->enabled()) {
            $this->reject('Integrasi ledger belum diaktifkan.');
        }
        $reference = config('financial_ownership.cutover_reference');
        if (! is_string($reference) || trim($reference) === '' || strlen($reference) > 200
            || DB::getDriverName() !== 'pgsql' || DB::transactionLevel() < 1) {
            $this->reject('Aktivasi ledger belum siap. Persetujuan cutover dan schema harus diverifikasi terlebih dahulu.');
        }
        if (DB::scalar("SELECT current_setting('transaction_isolation')") !== 'read committed') {
            $this->reject('Operasi ledger memerlukan transaksi PostgreSQL READ COMMITTED.');
        }
        // Gate adalah keputusan bisnis eksplisit. Catalog hanya memverifikasi readiness sesudah gate ON.
        $values = [];
        $bindings = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach (explode(' ', $columns) as $column) {
                $values[] = '(?,?)';
                array_push($bindings, $table, $column);
            }
        }
        $missing = DB::scalar('WITH required(table_name,column_name) AS (VALUES '.implode(',', $values).")
            SELECT count(*) FROM required r WHERE NOT EXISTS (SELECT 1 FROM information_schema.columns c
                WHERE c.table_schema='public' AND c.table_name=r.table_name AND c.column_name=r.column_name)", $bindings);
        if ((int) $missing !== 0) {
            $this->reject('Schema ledger belum siap. Hubungi administrator sistem; operasi dibatalkan.');
        }
        $migrations = DB::table('migrations')->whereIn('migration', [
            '2026_11_12_000008_expand_billing_schema', '2026_11_13_000009_add_billing_integrity_guards',
        ])->count();
        $expectedTriggers = [];
        foreach (['invoices' => 'invoice', 'payments' => 'payment', 'financial_ownership_periods' => 'period',
            'invoice_revisions' => 'revision', 'billing_requests' => 'request', 'billing_actions' => 'action'] as $table => $prefix) {
            foreach (['write', 'final', 'truncate'] as $suffix) {
                $expectedTriggers[] = [$table, 'billing_'.$prefix.'_'.$suffix];
            }
        }
        $triggerValues = implode(',', array_fill(0, count($expectedTriggers), '(?,?)'));
        $triggerBindings = array_merge(...$expectedTriggers);
        $triggers = DB::scalar('WITH required(table_name,trigger_name) AS (VALUES '.$triggerValues.")
            SELECT count(*) FROM required r JOIN pg_class c ON c.relname=r.table_name JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_trigger t ON t.tgrelid=c.oid AND t.tgname=r.trigger_name WHERE n.nspname='public'
                AND NOT t.tgisinternal AND t.tgenabled IN ('O','A')
                AND (r.trigger_name NOT LIKE '%_final' OR (t.tgdeferrable AND t.tginitdeferred))", $triggerBindings);
        $functions = DB::scalar("SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace
            AND proname IN ('billing_guard_fail','billing_lock_invoice','billing_guard_invoice','billing_guard_revision','billing_guard_payment',
                'billing_guard_period','billing_guard_request','billing_guard_action','billing_validate_ownership','billing_validate_invoice',
                'billing_deferred_check','billing_guard_truncate') AND NOT prosecdef AND provolatile='v'");
        $precision = DB::scalar("SELECT count(*) FROM information_schema.columns WHERE table_schema='public'
            AND ((table_name='financial_ownership_periods' AND column_name IN ('effective_from','effective_to','recorded_at'))
                OR (table_name='billing_actions' AND column_name='occurred_at'))
            AND data_type='timestamp with time zone' AND datetime_precision=6");
        if ($migrations !== 2 || (int) $triggers !== 18 || (int) $functions !== 12 || (int) $precision !== 4) {
            $this->reject('Guard ledger belum siap. Hubungi administrator sistem; operasi dibatalkan.');
        }
    }

    // Dipanggil setelah User locks dan INSERT resident, di transaksi ResidentService yang sama.
    public function createForNewResident(User $actor, Elderly $resident): void
    {
        $this->assertReady();
        Gate::forUser($actor)->authorize('create', Elderly::class);
        if (! $resident->wasRecentlyCreated || DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->exists()) {
            $this->reject('Root ledger hanya dapat dibuat untuk lansia baru melalui transaksi pembuatan lansia.');
        }
        $at = $this->serverInstant();
        $period = ['id' => (string) Str::uuid(), 'elderly_id' => $resident->id, 'family_id' => $resident->family_id,
            'previous_period_id' => null, 'effective_from' => $at, 'effective_to' => null,
            'recorded_by' => $actor->id, 'recorded_at' => $at, 'source' => 'resident_create'];
        DB::table('financial_ownership_periods')->insert($period);
        $this->action($actor, $period['id'], 'ownership_opened', $at);
    }

    // Caller telah mengunci actor/old/new Users sorted, resident, lalu memvalidasi expected family.
    public function transfer(User $actor, Elderly $resident, User $newFamily): void
    {
        $this->assertReady();
        Gate::forUser($actor)->authorize('reassignFamily', $resident);
        if ($newFamily->role !== 'keluarga' || ! $newFamily->is_active || $newFamily->id === $resident->family_id) {
            throw ValidationException::withMessages(['family_id' => 'Pilih akun keluarga aktif yang berbeda dari keluarga saat ini.']);
        }
        $periods = DB::table('financial_ownership_periods')->where('elderly_id', $resident->id)->orderBy('id')->lockForUpdate()->get();
        if ($periods->isEmpty()) {
            $this->reject('Lansia belum memiliki ledger. Transfer memerlukan cutover capture yang disetujui melalui prosedur terpisah.');
        }
        // Bukan implicit capture/repair. Chain stale/invalid ditolak sebelum close.
        DB::select('SELECT public.billing_validate_ownership(?)', [$resident->id]);
        $open = $periods->whereNull('effective_to');
        if ($open->count() !== 1 || $open->first()->family_id !== $resident->family_id) {
            $this->reject('Ledger keluarga tidak konsisten. Transfer dibatalkan untuk pemeriksaan administrator sistem.');
        }
        $previous = $open->first();
        $at = $this->serverInstant($previous->effective_from);
        $changed = DB::table('financial_ownership_periods')->where('id', $previous->id)->whereNull('effective_to')->update(['effective_to' => $at]);
        if ($changed !== 1) {
            $this->reject('Ledger telah berubah. Muat ulang halaman sebelum melanjutkan.');
        }
        $this->action($actor, $previous->id, 'ownership_closed', $at);
        $successor = ['id' => (string) Str::uuid(), 'elderly_id' => $resident->id, 'family_id' => $newFamily->id,
            'previous_period_id' => $previous->id, 'effective_from' => $at, 'effective_to' => null,
            'recorded_by' => $actor->id, 'recorded_at' => $at, 'source' => 'family_transfer'];
        DB::table('financial_ownership_periods')->insert($successor);
        $resident->family_id = $newFamily->id;
        $resident->save();
        $this->action($actor, $successor['id'], 'ownership_opened', $at);
    }

    protected function serverInstant(?string $after = null): string
    {
        $sample = DB::selectOne("WITH sampled AS MATERIALIZED (SELECT clock_timestamp() AS instant)
            SELECT to_char(instant AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS.US') || '+00' AS instant,
                (CAST(? AS timestamptz) IS NULL OR instant > CAST(? AS timestamptz)) AS chronological FROM sampled", [$after, $after]);
        if (! $sample->chronological) {
            $this->reject('Waktu ledger belum dapat dilanjutkan. Operasi dibatalkan; coba lagi setelah pemeriksaan waktu server.');
        }

        return $sample->instant;
    }

    private function action(User $actor, string $period, string $action, string $at): void
    {
        DB::table('billing_actions')->insert(['id' => (string) Str::uuid(), 'billing_request_id' => null, 'actor_id' => $actor->id,
            'invoice_id' => null, 'ownership_period_id' => $period, 'payment_id' => null, 'revision_id' => null, 'invoice_sequence' => null,
            'action' => $action, 'occurred_at' => $at, 'reason_code' => null, 'private_note' => null, 'details' => '{"v":1}']);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
