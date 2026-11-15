<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

final class InvoiceSourceReader
{
    // Pre-read di luar transaksi; dipanggil ulang setelah parent lock, tanpa late User lock.
    public function userIds(string $actor, string $elderly): array
    {
        $ids = [$actor, DB::table('public.elderlies')->where('id', $elderly)->value('family_id')];
        foreach (DB::table('public.financial_ownership_periods')->where('elderly_id', $elderly)->get(['family_id', 'recorded_by']) as $row) {
            array_push($ids, $row->family_id, $row->recorded_by);
        }
        $audit = DB::table('public.billing_actions as a')->join('public.financial_ownership_periods as p', 'p.id', '=', 'a.ownership_period_id')
            ->where('p.elderly_id', $elderly)->pluck('a.actor_id')->all();
        $ids = array_values(array_unique(array_filter([...$ids, ...$audit])));
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function read(object $resident, string $month): InvoiceSourceSnapshot
    {
        // Membaca seluruh enrollment resident: closed dan record di luar bulan tidak disembunyikan.
        $enrollments = DB::table('public.service_enrollments')->where('elderly_id', $resident->id)->orderBy('id')->lockForUpdate()
            ->select(['id', 'elderly_id', 'service_class_id', 'started_at', 'ended_at'])->selectRaw('monthly_price::text AS monthly_price')->get()
            ->map(fn ($e) => new EnrollmentSnapshot($e->id, $e->elderly_id, $e->service_class_id, $e->started_at, $e->ended_at, $e->monthly_price))->all();
        $periods = DB::table('public.financial_ownership_periods')->where('elderly_id', $resident->id)->orderBy('id')->lockForUpdate()->get()
            ->map(fn ($p) => new OwnershipPeriodSnapshot($p->id, $p->elderly_id, $p->family_id, $p->previous_period_id,
                $p->effective_from, $p->effective_to, $p->recorded_by, $p->recorded_at, $p->source))->all();
        $root = DB::table('public.invoices')->where('elderly_id', $resident->id)->where('billing_month', $month)->lockForUpdate()->value('id');
        $actions = DB::table('public.billing_actions as a')->join('public.financial_ownership_periods as p', 'p.id', '=', 'a.ownership_period_id')
            ->where('p.elderly_id', $resident->id)->orderBy('a.id')->get(['a.ownership_period_id', 'a.actor_id', 'a.action', 'a.occurred_at'])
            ->map(fn ($a) => new OwnershipActionSnapshot($a->ownership_period_id, $a->actor_id, $a->action, $a->occurred_at))->all();

        return new InvoiceSourceSnapshot($resident->id, $resident->family_id, $resident->is_active,
            $enrollments, $periods, $actions, $root);
    }

    public function clock(): string
    {
        return DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");
    }
}
