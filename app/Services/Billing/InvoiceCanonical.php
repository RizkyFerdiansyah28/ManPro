<?php

declare(strict_types=1);

namespace App\Services\Billing;

final class InvoiceCanonical
{
    private const CONTENT_FIELDS = ['elderly_id', 'billing_month', 'service_enrollment_id', 'financial_owner_id',
        'ownership_period_id', 'issued_by', 'issued_at', 'policy_version', 'monthly_price', 'effective_started_at',
        'effective_ended_at', 'chargeable_days', 'days_in_month', 'amount', 'currency', 'due_date', 'grace_period_ends_on'];

    public static function digest(string $domain, array $fields): string
    {
        return hash('sha256', $domain."\n".json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function intent(InvoiceIssueCommand $command): string
    {
        return self::digest('silansia.invoice.issue.intent.v1', ['issue', $command->elderlyId,
            $command->billingMonth, $command->confirmation->fingerprint]);
    }

    public function source(InvoiceSourceSnapshot $snapshot, string $month): string
    {
        $enrollments = array_map(fn ($e) => [$e->id, $e->elderlyId, $e->serviceClassId,
            $this->sourceInstant($e->startedAt), $this->sourceInstant($e->endedAt), $e->monthlyPrice], $snapshot->enrollments);
        $periods = array_map(fn ($p) => [$p->id, $p->elderlyId, $p->familyId, $p->previousPeriodId,
            $this->sourceInstant($p->effectiveFrom), $this->sourceInstant($p->effectiveTo), $p->recordedBy,
            $this->sourceInstant($p->recordedAt), $p->source], $snapshot->periods);
        $actions = array_map(fn ($a) => [$a->periodId, $a->actorId, $a->action, $this->sourceInstant($a->occurredAt)], $snapshot->actions);
        foreach ([&$enrollments, &$periods, &$actions] as &$rows) {
            sort($rows, SORT_REGULAR);
        }
        unset($rows);

        return self::digest('silansia.invoice.source.v1', ['pg-billing-source-v1', $snapshot->elderlyId,
            $snapshot->currentFamilyId, $month, InvoiceCalculator::POLICY_VERSION, $enrollments, $periods, $actions]);
    }

    public function content(array $facts): string
    {
        $ordered = [];
        foreach (self::CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $facts)) {
                throw new InvoiceIssuanceFailure('invalid_content');
            }
            $value = $facts[$field];
            if (in_array($field, ['monthly_price', 'amount'], true)) {
                if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,11})\.[0-9]{2}\z/', $value)) {
                    throw new InvoiceIssuanceFailure('invalid_content');
                }
            } elseif (in_array($field, ['issued_at', 'effective_started_at', 'effective_ended_at'], true)) {
                $value = BillingTime::format(BillingTime::instant($value));
            } elseif (in_array($field, ['chargeable_days', 'days_in_month'], true)) {
                if (! is_int($value)) {
                    throw new InvoiceIssuanceFailure('invalid_content');
                }
            } elseif (str_ends_with($field, '_id') || $field === 'issued_by') {
                if (! is_string($value) || ! BillingTime::uuid($value)) {
                    throw new InvoiceIssuanceFailure('invalid_content');
                }
                $value = strtolower($value);
            } elseif (! is_string($value)) {
                throw new InvoiceIssuanceFailure('invalid_content');
            }
            $ordered[] = $value;
        }

        return self::digest('silansia.invoice.revision.content.v1', $ordered);
    }

    private function sourceInstant(?string $at): ?string
    {
        if ($at === null) {
            return null;
        }
        try {
            return BillingTime::format(BillingTime::instant($at));
        } catch (InvoiceCalculationException) {
            // HOLD snapshot tetap dapat difingerprint; invalid source tidak diperbaiki/diabaikan.
            return $at;
        }
    }
}
