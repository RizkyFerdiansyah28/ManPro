<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PaymentHttpReader
{
    public function __construct(private readonly PaymentReadiness $readiness) {}

    public function invoice(User $actor, string $id): object
    {
        $this->readiness->entry();
        $query = DB::table('public.invoices as i')->join('public.elderlies as e', 'e.id', '=', 'i.elderly_id')
            ->join('public.users as f', 'f.id', '=', 'i.bill_to_family_id')
            ->where('i.id', $id)->where('i.billing_schema_version', 1)->whereNotNull('i.current_revision_id');
        $this->scope($query, $actor);
        $row = $query->select('i.*', 'e.name as resident_name_current', 'f.name as owner_name_current')->selectRaw('i.amount::text AS amount')->first();
        abort_unless($row, 404);

        return $row;
    }

    public function payment(User $actor, string $invoice, string $id): object
    {
        $this->invoice($actor, $invoice);
        $query = DB::table('public.payments as p')->join('public.invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('public.elderlies as e', 'e.id', '=', 'i.elderly_id')
            ->where('p.invoice_id', $invoice)->where('p.id', $id)->where('i.billing_schema_version', 1)
            ->where('p.billing_schema_version', 1)->where('p.submission_channel', 'family_upload');
        $this->scope($query, $actor);
        $row = $query->select('p.*')->selectRaw('p.amount::text AS amount')->first();
        abort_unless($row, 404);

        return $row;
    }

    public function index(User $actor, array $filters): array
    {
        $this->readiness->entry();
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'keluarga'], true), 403);
        if ($actor->role === 'admin') {
            $query = DB::table('public.payments as p')->join('public.invoices as i', 'i.id', '=', 'p.invoice_id')
                ->join('public.elderlies as e', 'e.id', '=', 'i.elderly_id')->join('public.users as f', 'f.id', '=', 'i.bill_to_family_id')
                ->where('p.billing_schema_version', 1)->where('i.billing_schema_version', 1)->where('p.submission_channel', 'family_upload')
                ->where('p.status', $filters['status'] ?? 'pending')->whereExists(fn ($q) => $q->selectRaw('1')->from('public.users')->where('id', $actor->id)->where('role', 'admin')->where('is_active', true))
                ->select('p.id', 'p.invoice_id', 'p.status', 'p.submitted_at', 'i.number as invoice_number', 'i.elderly_id', 'i.bill_to_family_id', 'i.billing_month', 'e.name as resident_name_current', 'f.name as owner_name_current')->selectRaw('p.amount::text AS amount');
        } else {
            $query = DB::table('public.invoices as i')->join('public.elderlies as e', 'e.id', '=', 'i.elderly_id')
                ->where('i.billing_schema_version', 1)->whereNotNull('i.current_revision_id');
            $this->scope($query, $actor);
            if (isset($filters['status'])) {
                $query->where('i.status', $filters['status']);
            }
            $query->select('i.id', 'i.elderly_id', 'i.number', 'i.billing_month', 'i.status', 'i.due_date', 'i.grace_period_ends_on', 'e.name as resident_name_current')->selectRaw('i.amount::text AS amount');
        }
        $page = (int) ($filters['page'] ?? 1);
        $rows = $query->orderBy($actor->role === 'admin' ? 'p.id' : 'i.id')->offset(($page - 1) * 20)->limit(21)->get()->all();
        $rows = array_map(function ($row) use ($actor) {
            $data = (array) $row;
            $data['resident'] = ['id' => $row->elderly_id, 'name_current' => $row->resident_name_current];
            if ($actor->role === 'admin') {
                $data['financial_owner'] = ['id' => $row->bill_to_family_id, 'name_current' => $row->owner_name_current];
                unset($data['elderly_id'], $data['bill_to_family_id'], $data['owner_name_current']);
            }
            unset($data['resident_name_current']);

            return $data;
        }, $rows);

        return ['data' => array_slice($rows, 0, 20), 'page' => $page, 'has_more' => count($rows) > 20];
    }

    public function detail(User $actor, string $id): array
    {
        $this->readiness->entry();

        return DB::transaction(function () use ($actor, $id) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            DB::statement("SET LOCAL statement_timeout='10s'");
            $row = $this->invoice($actor, $id);
            $payments = DB::table('public.payments')->where('invoice_id', $id)->where('billing_schema_version', 1)
                ->where('submission_channel', 'family_upload')->orderBy('submitted_at')->get(['id', 'status', 'submitted_at', 'public_rejection_reason']);

            return ['invoice' => $this->invoiceResource($row), 'payments' => $payments->all()];
        });
    }

    public function reviewDetail(User $actor, string $invoice, string $payment): array
    {
        $this->readiness->entry();
        abort_unless($actor->isActiveAdmin(), 403);

        return DB::transaction(function () use ($actor, $invoice, $payment) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            DB::statement("SET LOCAL statement_timeout='10s'");
            $root = $this->invoice($actor, $invoice);
            $row = $this->payment($actor, $invoice, $payment);

            return ['invoice' => $this->invoiceResource($root, true), 'payment' => array_intersect_key((array) $row, array_flip([
                'id', 'invoice_id', 'invoice_revision_id', 'amount', 'status', 'submitted_at', 'verified_at', 'public_rejection_reason',
            ]))];
        });
    }

    public function invoiceResource(object $row, bool $admin = false): array
    {
        $data = array_intersect_key((array) $row, array_flip(['id', 'elderly_id', 'number', 'billing_month', 'amount', 'status', 'due_date', 'grace_period_ends_on', 'current_revision_id']));
        $data['resident'] = ['id' => $row->elderly_id, 'name_current' => $row->resident_name_current];
        if ($admin) {
            $data['financial_owner'] = ['id' => $row->bill_to_family_id, 'name_current' => $row->owner_name_current];
        }

        return $data;
    }

    private function scope($query, User $actor): void
    {
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'keluarga'], true), 403);
        $query->whereExists(fn ($q) => $q->selectRaw('1')->from('public.users')->where('id', $actor->id)->where('role', $actor->role)->where('is_active', true));
        if ($actor->role === 'keluarga') {
            $query->where('i.bill_to_family_id', $actor->id)->where('e.family_id', $actor->id);
        }
    }
}
