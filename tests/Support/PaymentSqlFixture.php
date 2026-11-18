<?php

namespace Tests\Support;

use App\Services\Billing\InvoiceCanonical;
use App\Services\Billing\InvoiceDueDates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Legal SQL graph mutations for races only. No operational revision/cancel writer.
final class PaymentSqlFixture
{
    public static function mutate(string $invoice, string $actor, bool $cancel): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        $root = DB::table('invoices')->where('id', $invoice)->first();
        $family = DB::table('elderlies')->where('id', $root->elderly_id)->value('family_id');
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::table('users')->whereIn('id', [$actor, $family, $root->bill_to_family_id])->orderBy('id')->lockForUpdate()->get();
        DB::table('elderlies')->where('id', $root->elderly_id)->lockForUpdate()->first();
        DB::table('service_enrollments')->where('id', $root->service_enrollment_id)->lockForUpdate()->first();
        DB::table('financial_ownership_periods')->where('elderly_id', $root->elderly_id)->orderBy('id')->lockForUpdate()->get();
        DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
        DB::table('payments')->where('invoice_id', $invoice)->orderBy('id')->lockForUpdate()->get();
        $root = DB::table('invoices')->where('id', $invoice)->first();
        $at = DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");
        $r = (array) DB::table('invoice_revisions')->where('id', $root->current_revision_id)->first();
        if ($cancel) {
            DB::table('invoices')->where('id', $invoice)->update(['status' => 'cancelled', 'cancelled_by' => $actor, 'cancelled_at' => $at, 'cancellation_reason' => 'Synthetic cancellation']);
            $op = 'cancel';
            $action = 'invoice_cancelled';
        } else {
            [$due,$grace] = InvoiceDueDates::fromIssuedAt($at);
            $r = [...$r, 'id' => (string) Str::uuid(), 'revision_no' => $r['revision_no'] + 1, 'previous_revision_id' => $r['id'],
                'issued_by' => $actor, 'issued_at' => $at, 'amount' => '2200.00', 'chargeable_days' => 22,
                'effective_ended_at' => '2026-10-23 00:00:00+07', 'due_date' => $due, 'grace_period_ends_on' => $grace,
                'reason_code' => 'material_correction', 'reason' => 'Synthetic correction'];
            $r['content_hash'] = (new InvoiceCanonical)->content($r + ['billing_month' => $root->billing_month,
                'financial_owner_id' => $root->bill_to_family_id, 'ownership_period_id' => $root->financial_ownership_period_id]);
            DB::table('invoice_revisions')->insert($r);
            DB::table('invoices')->where('id', $invoice)->update(['current_revision_id' => $r['id'], 'amount' => $r['amount'],
                'due_date' => $due, 'grace_period_ends_on' => $grace]);
            $op = 'revise';
            $action = 'invoice_revised';
        }
        $q = (string) Str::uuid();
        DB::table('billing_requests')->insert(['id' => $q, 'actor_id' => $actor, 'request_id' => (string) Str::uuid(), 'operation' => $op,
            'intent_hash' => hash('sha256', 'synthetic-'.$q), 'invoice_id' => $invoice, 'outcome_payment_id' => null, 'outcome_revision_id' => $r['id'], 'completed_at' => $at]);
        DB::table('billing_actions')->insert(['id' => (string) Str::uuid(), 'billing_request_id' => $q, 'actor_id' => $actor, 'invoice_id' => $invoice,
            'ownership_period_id' => null, 'payment_id' => null, 'revision_id' => $r['id'],
            'invoice_sequence' => (int) DB::table('billing_actions')->where('invoice_id', $invoice)->max('invoice_sequence') + 1,
            'action' => $action, 'occurred_at' => $at, 'reason_code' => 'fixture_correction', 'private_note' => null, 'details' => '{"v":1}']);
        DB::select('SELECT public.billing_validate_invoice(?)', [$invoice]);
        DB::commit();
    }
}
