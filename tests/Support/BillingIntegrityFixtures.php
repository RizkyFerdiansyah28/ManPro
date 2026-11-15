<?php

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use App\Models\User;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Seluruh nilai/waktu adalah fixture sintetis. Bukan writer atau backfill aplikasi.
trait BillingIntegrityFixtures
{
    protected function billingId(): string
    {
        return (string) Str::uuid();
    }

    protected function billingGraph(string $amount = '3100.00'): array
    {
        return DB::transaction(function () use ($amount) {
            (new ServiceClassSeeder)->run();
            // Committed fixtures survive between suites; Faker uniqueness is process-local.
            $family = User::factory()->create(['role' => 'keluarga', 'is_active' => true, 'email' => 'integrity-'.Str::uuid().'@example.test']);
            $nextFamily = User::factory()->create(['role' => 'keluarga', 'is_active' => true, 'email' => 'integrity-'.Str::uuid().'@example.test']);
            $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'integrity-'.Str::uuid().'@example.test']);
            $elderly = Elderly::create(['family_id' => $family->id, 'name' => 'Fixture integrity', 'birth_date' => '1950-01-01', 'is_active' => true]);
            $enrollment = ServiceEnrollment::create(['elderly_id' => $elderly->id,
                'service_class_id' => DB::table('service_classes')->where('code', 'vip')->value('id'),
                'monthly_price' => '3100.00', 'started_at' => '2026-09-01 00:00:00+07']);
            $p = ['family' => $family->id, 'next_family' => $nextFamily->id, 'admin' => $admin->id, 'elderly' => $elderly->id, 'enrollment' => $enrollment->id];
            $period = ['id' => $this->billingId(), 'elderly_id' => $p['elderly'], 'family_id' => $p['family'],
                'previous_period_id' => null, 'effective_from' => '2026-09-01 00:00:00.123456+07', 'effective_to' => null,
                'recorded_by' => $p['admin'], 'recorded_at' => '2026-09-01 00:00:00.123456+07', 'source' => 'cutover_capture'];
            DB::table('financial_ownership_periods')->insert($period);
            $this->billingOwnershipAction($period, 'ownership_captured', $p['admin'], $period['recorded_at']);
            $invoice = ['id' => $this->billingId(), 'number' => 'FIXTURE-'.$this->billingId(), 'elderly_id' => $p['elderly'],
                'service_enrollment_id' => $p['enrollment'], 'billing_month' => '2026-10-01', 'amount' => $amount, 'currency' => 'IDR',
                'due_date' => '2026-11-08', 'grace_period_ends_on' => '2026-11-11', 'status' => $amount === '0.00' ? 'paid' : 'unpaid',
                'paid_at' => $amount === '0.00' ? '2026-11-01 00:00:00+07' : null, 'billing_schema_version' => 1,
                'bill_to_family_id' => $p['family'], 'financial_ownership_period_id' => $period['id'], 'initial_issued_by' => $p['admin'],
                'initial_issued_at' => '2026-11-01 00:00:00.123456+07', 'current_revision_id' => $this->billingId()];
            $revision = ['id' => $invoice['current_revision_id'], 'invoice_id' => $invoice['id'], 'elderly_id' => $p['elderly'],
                'service_enrollment_id' => $p['enrollment'], 'revision_no' => 1, 'previous_revision_id' => null, 'issued_by' => $p['admin'],
                'issued_at' => $invoice['initial_issued_at'], 'policy_version' => 'synthetic-fixture-v1', 'monthly_price' => '3100.00',
                'effective_started_at' => '2026-10-01 00:00:00+07', 'effective_ended_at' => '2026-11-01 00:00:00+07',
                'chargeable_days' => 31, 'days_in_month' => 31, 'amount' => $amount, 'currency' => 'IDR',
                'due_date' => $invoice['due_date'], 'grace_period_ends_on' => $invoice['grace_period_ends_on'],
                'reason_code' => 'initial_issue', 'reason' => null, 'content_hash' => str_repeat('a', 64)];
            DB::table('invoices')->insert($invoice);
            DB::table('invoice_revisions')->insert($revision);
            $g = compact('p', 'period', 'invoice', 'revision');
            $q = $this->billingRequest($g, 'issue', $p['admin'], $revision['id']);
            $this->billingAction($g, $q, 'invoice_issued', $revision['id'], null, $revision['issued_at']);
            if ($amount === '0.00') {
                $this->billingAction($g, $q, 'zero_charge', $revision['id'], null, $revision['issued_at']);
            }

            return $g + ['request' => $q];
        });
    }

    protected function billingRequest(array $g, string $operation, string $actor, ?string $revision, ?string $payment = null, ?string $requestId = null): array
    {
        $q = ['id' => $this->billingId(), 'actor_id' => $actor, 'request_id' => $requestId ?? $this->billingId(),
            'operation' => $operation, 'intent_hash' => hash('sha256', $operation.'|'.$g['invoice']['id'].'|'.$revision.'|'.$payment),
            'invoice_id' => $g['invoice']['id'], 'outcome_payment_id' => $payment, 'outcome_revision_id' => $revision,
            'completed_at' => '2026-11-05 00:00:00.123456+07'];
        DB::table('billing_requests')->insert($q);

        return $q;
    }

    protected function billingAction(array $g, array $q, string $action, string $revision, ?string $payment, string $at): array
    {
        $row = ['id' => $this->billingId(), 'billing_request_id' => $q['id'], 'actor_id' => $q['actor_id'], 'invoice_id' => $g['invoice']['id'],
            'ownership_period_id' => null, 'payment_id' => $payment, 'revision_id' => $revision,
            'invoice_sequence' => (int) DB::table('billing_actions')->where('invoice_id', $g['invoice']['id'])->max('invoice_sequence') + 1,
            'action' => $action, 'occurred_at' => $at, 'reason_code' => in_array($action, ['invoice_revised', 'invoice_cancelled', 'payment_rejected'], true) ? 'fixture_correction' : null,
            'private_note' => null, 'details' => '{"v":1}'];
        DB::table('billing_actions')->insert($row);

        return $row;
    }

    protected function billingOwnershipAction(array $period, string $action, string $actor, string $at): void
    {
        DB::table('billing_actions')->insert(['id' => $this->billingId(), 'billing_request_id' => null, 'actor_id' => $actor,
            'invoice_id' => null, 'ownership_period_id' => $period['id'], 'payment_id' => null, 'revision_id' => null, 'invoice_sequence' => null,
            'action' => $action, 'occurred_at' => $at, 'reason_code' => null, 'private_note' => null, 'details' => '{"v":1}']);
    }

    protected function billingLocks(array $g): void
    {
        DB::table('users')->whereIn('id', [$g['p']['admin'], $g['p']['family'], $g['p']['next_family']])->orderBy('id')->lockForUpdate()->get();
        DB::table('elderlies')->where('id', $g['p']['elderly'])->lockForUpdate()->first();
        DB::table('service_enrollments')->where('id', $g['p']['enrollment'])->lockForUpdate()->first();
        DB::table('financial_ownership_periods')->where('elderly_id', $g['p']['elderly'])->orderBy('id')->lockForUpdate()->get();
        DB::table('invoices')->where('id', $g['invoice']['id'])->lockForUpdate()->first();
        DB::table('payments')->where('invoice_id', $g['invoice']['id'])->orderBy('id')->lockForUpdate()->get();
    }

    protected function billingRevise(array $g, string $amount = '2200.00', ?string $requestId = null): array
    {
        $r = [...$g['revision'], 'id' => $this->billingId(), 'revision_no' => $g['revision']['revision_no'] + 1,
            'previous_revision_id' => $g['revision']['id'], 'amount' => $amount, 'issued_by' => $g['p']['admin'], 'issued_at' => '2026-11-02 00:00:00.123456+07',
            'due_date' => '2026-11-09', 'grace_period_ends_on' => '2026-11-12', 'reason_code' => 'material_correction', 'reason' => 'Fixture material correction'];
        DB::table('invoice_revisions')->insert($r);
        $projection = ['current_revision_id' => $r['id'], 'amount' => $amount, 'due_date' => $r['due_date'], 'grace_period_ends_on' => $r['grace_period_ends_on']];
        if ($amount === '0.00') {
            $projection += ['status' => 'paid', 'paid_at' => '2026-11-02 00:00:00+07'];
        }
        DB::table('invoices')->where('id', $g['invoice']['id'])->update($projection);
        $q = $this->billingRequest($g, 'revise', $g['p']['admin'], $r['id'], null, $requestId);
        $this->billingAction($g, $q, 'invoice_revised', $r['id'], null, $r['issued_at']);
        if ($amount === '0.00') {
            $this->billingAction($g, $q, 'zero_charge', $r['id'], null, $r['issued_at']);
        }

        return [...$g, 'revision' => $r, 'invoice' => [...$g['invoice'], ...$projection], 'request' => $q];
    }

    protected function billingSubmit(array $g): array
    {
        $p = ['id' => $this->billingId(), 'invoice_id' => $g['invoice']['id'], 'submitted_by' => $g['p']['family'], 'amount' => $g['revision']['amount'],
            'proof_path' => 'synthetic/private.jpg', 'submitted_at' => '2026-11-03 00:00:00+07', 'status' => 'pending',
            'verified_by' => null, 'verified_at' => null, 'verification_notes' => null, 'billing_schema_version' => 1,
            'invoice_revision_id' => $g['revision']['id'], 'submission_channel' => 'family_upload', 'proof_sha256' => str_repeat('b', 64),
            'proof_size_bytes' => 128, 'proof_mime' => 'image/jpeg', 'proof_disk' => 'local', 'public_rejection_reason' => null];
        DB::table('payments')->insert($p);
        $q = $this->billingRequest($g, 'submit', $g['p']['family'], $g['revision']['id'], $p['id']);
        $this->billingAction($g, $q, 'payment_submitted', $g['revision']['id'], $p['id'], '2026-11-03 00:00:00.123456+07');

        return [...$g, 'payment' => $p];
    }

    protected function billingReview(array $g, string $status): array
    {
        $p = $g['payment'];
        DB::table('payments')->where('id', $p['id'])->update(['status' => $status, 'verified_by' => $g['p']['admin'],
            'verified_at' => '2026-11-04 00:00:00+07', 'public_rejection_reason' => $status === 'rejected' ? 'Fixture rejected' : null]);
        if ($status === 'verified') {
            DB::table('invoices')->where('id', $g['invoice']['id'])->update(['status' => 'paid', 'paid_at' => '2026-11-04 00:00:00+07']);
        }
        $q = $this->billingRequest($g, $status === 'verified' ? 'verify' : 'reject', $g['p']['admin'], $g['revision']['id'], $p['id']);
        $this->billingAction($g, $q, 'payment_'.$status, $g['revision']['id'], $p['id'], '2026-11-04 00:00:00.123456+07');

        return [...$g, 'request' => $q];
    }

    protected function billingTransfer(array $g): array
    {
        $at = '2026-11-05 00:00:00.123456+07';
        DB::table('financial_ownership_periods')->where('id', $g['period']['id'])->update(['effective_to' => $at]);
        $this->billingOwnershipAction($g['period'], 'ownership_closed', $g['p']['admin'], $at);
        $next = [...$g['period'], 'id' => $this->billingId(), 'family_id' => $g['p']['next_family'], 'previous_period_id' => $g['period']['id'],
            'effective_from' => $at, 'effective_to' => null, 'recorded_at' => $at, 'source' => 'family_transfer'];
        DB::table('financial_ownership_periods')->insert($next);
        DB::table('elderlies')->where('id', $g['p']['elderly'])->update(['family_id' => $next['family_id']]);
        $this->billingOwnershipAction($next, 'ownership_opened', $g['p']['admin'], $at);

        return $next;
    }
}
