<?php

namespace App\Services\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Http\Resources\Billing\InvoiceResource;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class InvoiceHttpReadService
{
    public function __construct(private InvoiceIssuanceReadiness $readiness) {}

    public function context(string $actor, ?string $resident = null): void
    {
        $row = $this->snapshot(fn () => DB::selectOne($this->actorSql().' SELECT EXISTS(SELECT 1 FROM actor) AS authorized,
            (CAST(? AS uuid) IS NULL OR EXISTS(SELECT 1 FROM public.elderlies WHERE id=CAST(? AS uuid))) AS found', [$actor, $resident, $resident]));
        abort_unless($row->found, 404);
    }

    public function index(string $actor, array $filters): array
    {
        $parameters = [$actor];
        $where = '';
        foreach (['elderly_id', 'billing_month', 'status'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where .= ' AND i.'.$field.'=?';
                $parameters[] = $filters[$field];
            }
        }
        $sort = match ($filters['sort'] ?? '') {
            'billing_month_asc' => 'i.billing_month ASC,i.id ASC', 'due_date_asc' => 'i.due_date ASC,i.id ASC',
            default => 'i.billing_month DESC,i.id ASC',
        };
        [$page, $limit, $offset] = $this->pagination($filters);
        $parameters[] = $limit;
        $parameters[] = $offset;
        $jsonSort = str_replace('i.', 'records.', $sort);
        $row = $this->snapshot(fn () => DB::selectOne($this->actorSql().",
            filtered AS MATERIALIZED (SELECT i.id FROM public.invoices i CROSS JOIN actor WHERE true {$where}),
            selected AS MATERIALIZED (SELECT i.id FROM public.invoices i JOIN filtered f ON f.id=i.id ORDER BY {$sort} LIMIT ? OFFSET ?),
            records AS (".$this->projection()." JOIN selected s ON s.id=i.id ORDER BY {$sort})
            SELECT /* invoice-http-snapshot */ EXISTS(SELECT 1 FROM actor) AS authorized,
                (SELECT count(*)::int FROM filtered) AS total,COALESCE((SELECT jsonb_agg(to_jsonb(records) ORDER BY {$jsonSort}) FROM records),'[]'::jsonb)::text AS records", $parameters));

        return ['data' => array_map(fn ($r) => (new InvoiceResource($r))->resolve(), json_decode($row->records, true, flags: JSON_THROW_ON_ERROR)),
            ...$this->page($row->total, $page, $limit, $filters, 'index')];
    }

    public function show(string $actor, string $invoice): array
    {
        $row = $this->snapshot(fn () => DB::selectOne($this->actorSql().',
            records AS ('.$this->projection().' CROSS JOIN actor WHERE i.id=CAST(? AS uuid))
            SELECT /* invoice-http-snapshot */ EXISTS(SELECT 1 FROM actor) AS authorized,
                (SELECT to_jsonb(records)::text FROM records) AS record', [$actor, $invoice]));
        abort_unless($row->record !== null, 404);

        return (new InvoiceResource(json_decode($row->record, true, flags: JSON_THROW_ON_ERROR), true))->resolve();
    }

    public function residentOptions(string $actor, array $filters): array
    {
        [$page, $limit, $offset] = $this->pagination($filters);
        $search = '%'.addcslashes(trim($filters['search'] ?? ''), '\\%_').'%';
        $row = $this->snapshot(fn () => DB::selectOne($this->actorSql().",
            filtered AS MATERIALIZED (SELECT e.id,e.name AS name_current,e.is_active FROM public.elderlies e CROSS JOIN actor WHERE e.name ILIKE ?),
            records AS (SELECT * FROM filtered ORDER BY name_current,id LIMIT ? OFFSET ?)
            SELECT /* invoice-http-snapshot */ EXISTS(SELECT 1 FROM actor) AS authorized,
                (SELECT count(*)::int FROM filtered) AS total,COALESCE((SELECT jsonb_agg(to_jsonb(records) ORDER BY records.name_current,records.id) FROM records),'[]'::jsonb)::text AS records", [$actor, $search, $limit, $offset]));

        return ['data' => json_decode($row->records, true, flags: JSON_THROW_ON_ERROR), ...$this->page($row->total, $page, $limit, $filters, 'resident-options')];
    }

    public function committedRequestExists(string $actor, string $requestId): bool
    {
        $row = $this->snapshot(fn () => DB::selectOne($this->actorSql().' SELECT /* invoice-http-reconcile-existence */
            EXISTS(SELECT 1 FROM actor) AS authorized,
            EXISTS(SELECT 1 FROM public.billing_requests r JOIN actor ON actor.id=r.actor_id WHERE r.request_id=CAST(? AS uuid)) AS found', [$actor, $requestId]));

        return $row->found;
    }

    private function snapshot(Closure $query): object
    {
        $this->readiness->entry();
        if (DB::transactionLevel() !== 0) {
            throw new InvoiceHttpException('billing_unavailable');
        }
        try {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ ONLY');
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement("SET LOCAL statement_timeout='15s'");
            DB::statement("SET LOCAL idle_in_transaction_session_timeout='20s'");
            $this->readiness->transaction();
            $row = $query();
            if (! $row || ! $row->authorized) {
                throw new InvoiceHttpException('unauthorized');
            }
            DB::commit();

            return $row;
        } catch (Throwable $error) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($error instanceof InvoiceHttpException || $error instanceof InvoiceIssuanceFailure) {
                throw $error;
            }
            $state = $error instanceof \PDOException ? ($error->errorInfo[0] ?? '') : '';
            throw new InvoiceHttpException(str_starts_with($state, '23') ? 'broken_graph' : 'infrastructure_failure');
        }
    }

    private function actorSql(): string
    {
        return "WITH actor AS (SELECT id FROM public.users WHERE id=CAST(? AS uuid) AND role='admin' AND is_active)";
    }

    private function projection(): string
    {
        // Response facts berasal dari satu SELECT snapshot. Validator tidak menyediakan data response,
        // hanya fail-closed integrity check; tidak ada query ORM/lazy relation setelah transaksi.
        return "SELECT i.id,i.number,i.elderly_id,i.billing_month,i.amount::text AS amount,i.currency,i.status,
            i.due_date,i.grace_period_ends_on,i.billing_schema_version,i.bill_to_family_id,i.financial_ownership_period_id,
            i.current_revision_id,i.initial_issued_at,i.initial_issued_by,i.service_enrollment_id,
            e.name AS resident_name_current,u.name AS owner_name_current,
            r.id AS revision_id,r.revision_no,r.amount::text AS revision_amount,r.monthly_price::text AS monthly_price,
            r.effective_started_at,r.effective_ended_at,r.chargeable_days,r.days_in_month,r.issued_at,r.policy_version,
            CASE WHEN i.billing_schema_version=1 THEN public.billing_validate_invoice(i.id)::text END AS checked,
            CASE WHEN i.billing_schema_version=1 THEN public.billing_validate_ownership(i.elderly_id)::text END AS ownership_checked,
            COALESCE((SELECT jsonb_agg(jsonb_build_object('action',a.action,'sequence',a.invoice_sequence,'occurred_at',a.occurred_at) ORDER BY a.invoice_sequence)
                FROM public.billing_actions a WHERE a.invoice_id=i.id AND a.action IN ('invoice_issued','zero_charge')),'[]'::jsonb) AS actions
            FROM public.invoices i LEFT JOIN public.invoice_revisions r ON r.invoice_id=i.id AND r.id=i.current_revision_id
            LEFT JOIN public.elderlies e ON e.id=i.elderly_id LEFT JOIN public.users u ON u.id=i.bill_to_family_id";
    }

    private function pagination(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $limit = (int) ($filters['per_page'] ?? 15);

        return [$page, $limit, ($page - 1) * $limit];
    }

    private function page(int $total, int $page, int $limit, array $filters, string $route): array
    {
        $url = fn ($number) => route('admin.billing.invoices.'.$route, absolute: false).'?'.http_build_query([...$filters, 'page' => $number, 'per_page' => $limit]);

        return ['total' => $total, 'current_page' => $page, 'per_page' => $limit, 'last_page' => max(1, (int) ceil($total / $limit)),
            'prev_page_url' => $page > 1 ? $url($page - 1) : null, 'next_page_url' => $page * $limit < $total ? $url($page + 1) : null];
    }
}
