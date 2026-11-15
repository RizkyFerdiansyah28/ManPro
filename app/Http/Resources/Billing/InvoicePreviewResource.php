<?php

namespace App\Http\Resources\Billing;

use App\Services\Billing\InvoiceIssuePreview;

final class InvoicePreviewResource
{
    public static function make(InvoiceIssuePreview $preview, ?array $confirmation): array
    {
        $eligible = $preview->eligibility;
        $calculation = $eligible->calculation;

        return ['kind' => 'preview', 'issued' => false, 'decision' => $eligible->decision->value,
            'reason' => $eligible->reason->value, 'calculation' => $calculation ? [
                'monthly_price' => $calculation->monthlyRate, 'amount' => $calculation->amount, 'currency' => 'IDR',
                'effective_started_at' => $calculation->effectiveStartedAt, 'effective_ended_at' => $calculation->effectiveEndedAt,
                'chargeable_days' => $calculation->chargeableDays, 'days_in_month' => $calculation->daysInMonth,
                'financial_owner_id' => $eligible->financialOwnerId, 'ownership_period_id' => $eligible->ownershipPeriodId,
            ] : null, 'confirmation' => $confirmation];
    }
}
