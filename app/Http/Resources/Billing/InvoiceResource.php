<?php

namespace App\Http\Resources\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Services\Billing\BillingTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function __construct(array $resource, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $managed = $r['billing_schema_version'] === 1;
        if (($r['billing_schema_version'] !== null && ! $managed)
            || ($managed && (! $r['revision_id'] || $r['revision_id'] !== $r['current_revision_id']
                || $r['revision_amount'] !== $r['amount'] || ! $r['bill_to_family_id'] || ! $r['financial_ownership_period_id']))) {
            throw new InvoiceHttpException('broken_graph');
        }
        $result = ['id' => $r['id'], 'number' => $r['number'], 'billing_month' => $r['billing_month'],
            'resident' => ['id' => $r['elderly_id'], 'name_current' => $r['resident_name_current']],
            'amount' => $r['amount'], 'currency' => $r['currency'], 'status' => $r['status'],
            'due_date' => $r['due_date'], 'grace_period_ends_on' => $r['grace_period_ends_on'],
            'record_kind' => $managed ? 'managed_v1' : 'legacy',
            'detail_url' => route('admin.billing.invoices.show', $r['id'], absolute: false)];
        if ($this->detail) {
            $result += ['financial_owner' => $managed ? ['id' => $r['bill_to_family_id'], 'name_current' => $r['owner_name_current']] : null,
                'ownership_period_id' => $managed ? $r['financial_ownership_period_id'] : null,
                'service_enrollment_id' => $r['service_enrollment_id'],
                'current_revision' => $managed ? ['id' => $r['revision_id'], 'revision_no' => $r['revision_no'],
                    'monthly_price' => $r['monthly_price'], 'amount' => $r['revision_amount'], 'currency' => $r['currency'],
                    'effective_started_at' => BillingTime::format(BillingTime::instant($r['effective_started_at'])),
                    'effective_ended_at' => BillingTime::format(BillingTime::instant($r['effective_ended_at'])),
                    'chargeable_days' => $r['chargeable_days'], 'days_in_month' => $r['days_in_month'],
                    'issued_at' => BillingTime::format(BillingTime::instant($r['issued_at'])), 'policy_version' => $r['policy_version']] : null,
                'initial_issued_at' => $managed ? BillingTime::format(BillingTime::instant($r['initial_issued_at'])) : null,
                'initial_issued_by' => $managed ? $r['initial_issued_by'] : null,
                'actions' => $managed ? array_map(fn ($a) => ['action' => $a['action'], 'sequence' => $a['sequence'],
                    'occurred_at' => BillingTime::format(BillingTime::instant($a['occurred_at']))], $r['actions']) : [],
                'legacy_warning' => $managed ? null : 'Legacy — snapshot finansial belum tersedia.'];
        }

        return $result;
    }
}
