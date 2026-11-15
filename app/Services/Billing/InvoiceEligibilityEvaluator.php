<?php

declare(strict_types=1);

namespace App\Services\Billing;

use DateTimeImmutable;

final class InvoiceEligibilityEvaluator
{
    public function __construct(private readonly InvoiceCalculator $calculator = new InvoiceCalculator) {}

    public function evaluate(InvoiceEligibilityInput $input): InvoiceEligibilityResult
    {
        try {
            [$monthStart, $monthEnd] = BillingTime::month($input->billingMonth);
        } catch (InvoiceCalculationException $exception) {
            return $this->reject($exception->reason);
        }
        if ($input->policyVersion !== InvoiceCalculator::POLICY_VERSION) {
            return $this->reject(InvoiceReason::UnsupportedPolicy);
        }
        if (! BillingTime::uuid($input->elderlyId) || ! BillingTime::uuid($input->currentFamilyId)
            || ($input->existingInvoiceId !== null && ! BillingTime::uuid($input->existingInvoiceId))) {
            return $this->reject(InvoiceReason::InvalidCommand);
        }
        try {
            $asOf = BillingTime::instant($input->asOf);
        } catch (InvoiceCalculationException) {
            return $this->reject(InvoiceReason::InvalidClock);
        }
        if ($asOf < $monthEnd) {
            return $this->reject(InvoiceReason::MonthNotEnded);
        }
        if ($input->existingInvoiceId !== null) {
            return $this->reject(InvoiceReason::ExistingInvoice);
        }
        if ($input->enrollments === null) {
            return $this->hold(InvoiceReason::IncompleteEvidence);
        }
        $candidates = [];
        $seen = [];
        foreach ($input->enrollments as $enrollment) {
            $id = strtolower($enrollment->id);
            if (! BillingTime::uuid($id) || isset($seen[$id]) || ! $this->sameId($enrollment->elderlyId, $input->elderlyId)
                || ! BillingTime::uuid($enrollment->serviceClassId)) {
                return $this->hold(InvoiceReason::InvalidEnrollment);
            }
            $seen[$id] = true;
            try {
                $start = BillingTime::instant($enrollment->startedAt);
                $end = $enrollment->endedAt === null ? null : BillingTime::instant($enrollment->endedAt);
            } catch (InvoiceCalculationException) {
                return $this->hold(InvoiceReason::InvalidEnrollment);
            }
            if ($end !== null && $end <= $start) {
                return $this->hold(InvoiceReason::InvalidEnrollment);
            }
            if ($start < $monthEnd && ($end === null || $end > $monthStart)) {
                $candidates[] = $enrollment;
            }
        }
        if ($candidates === []) {
            return new InvoiceEligibilityResult(InvoiceDecision::Skip, InvoiceReason::NoService);
        }
        if (count($candidates) !== 1) {
            return $this->hold(InvoiceReason::MultipleEnrollments);
        }
        $enrollment = $candidates[0];
        try {
            $calculation = $this->calculator->calculate(new InvoiceCalculationInput($input->billingMonth,
                $enrollment->startedAt, $enrollment->endedAt, $enrollment->monthlyPrice, $input->policyVersion));
        } catch (InvoiceCalculationException $exception) {
            return in_array($exception->reason, [InvoiceReason::UnsupportedRuntime, InvoiceReason::Overflow], true)
                ? $this->reject($exception->reason) : $this->hold($exception->reason);
        }
        if ($input->ownershipPeriods === null || $input->ownershipActions === null) {
            return $this->hold(InvoiceReason::IncompleteEvidence);
        }
        if ($input->ownershipPeriods === []) {
            return $this->hold(InvoiceReason::MissingLedger);
        }
        $chain = $this->chain($input, $asOf);
        if ($chain instanceof InvoiceReason) {
            return $this->hold($chain);
        }
        $families = [];
        $witnesses = [];
        // Gunakan kembali instant sumber yang sudah tervalidasi. Normalisasi UTC di output
        // dapat melewati batas tahun representasi input; jangan parse ulang output sebagai input baru.
        $a = max(BillingTime::instant($enrollment->startedAt), $monthStart);
        $b = $enrollment->endedAt === null ? $monthEnd : min(BillingTime::instant($enrollment->endedAt), $monthEnd);
        foreach ($chain as $period) {
            if ($period['from'] < $monthEnd && ($period['to'] === null || $period['to'] > $monthStart)) {
                $families[strtolower($period['row']->familyId)] = true;
            }
            if ($period['from'] <= $a && ($period['to'] === null || $period['to'] >= $b)) {
                $witnesses[] = $period['row'];
            }
        }
        if (count($families) > 1) {
            return $this->hold(InvoiceReason::MixedOwners);
        }
        if (count($witnesses) !== 1) {
            return $this->hold(InvoiceReason::PartialCoverage);
        }

        // K02: residentActive tidak menentukan kewajiban layanan historis. Tidak ada authorization di sini.
        return new InvoiceEligibilityResult(InvoiceDecision::Issue, InvoiceReason::Eligible, $calculation,
            strtolower($witnesses[0]->id), strtolower($witnesses[0]->familyId));
    }

    /** @return list<array{row: OwnershipPeriodSnapshot, from: DateTimeImmutable, to: ?DateTimeImmutable}>|InvoiceReason */
    private function chain(InvoiceEligibilityInput $input, DateTimeImmutable $asOf): array|InvoiceReason
    {
        $rows = [];
        $ids = [];
        foreach ($input->ownershipPeriods as $period) {
            $id = strtolower($period->id);
            if (! BillingTime::uuid($id) || isset($ids[$id]) || ! $this->sameId($period->elderlyId, $input->elderlyId)
                || ! BillingTime::uuid($period->familyId) || ! BillingTime::uuid($period->recordedBy)
                || ($period->previousPeriodId !== null && ! BillingTime::uuid($period->previousPeriodId))) {
                return InvoiceReason::InvalidChain;
            }
            $ids[$id] = true;
            try {
                $from = BillingTime::instant($period->effectiveFrom);
                $to = $period->effectiveTo === null ? null : BillingTime::instant($period->effectiveTo);
                $recorded = BillingTime::instant($period->recordedAt);
            } catch (InvoiceCalculationException) {
                return InvoiceReason::InvalidChain;
            }
            if ($from != $recorded || $from > $asOf || ($to !== null && ($to <= $from || $to > $asOf))) {
                return InvoiceReason::InvalidChain;
            }
            $rows[] = ['row' => $period, 'from' => $from, 'to' => $to];
        }
        usort($rows, fn ($a, $b) => ($a['from'] <=> $b['from']) ?: strcmp(strtolower($a['row']->id), strtolower($b['row']->id)));
        $previous = null;
        foreach ($rows as $period) {
            $row = $period['row'];
            if ($previous === null) {
                if ($row->previousPeriodId !== null || ! in_array($row->source, ['resident_create', 'cutover_capture'], true)) {
                    return InvoiceReason::InvalidChain;
                }
            } elseif ($row->source !== 'family_transfer' || $row->previousPeriodId === null
                || ! $this->sameId($row->previousPeriodId, $previous['row']->id) || $previous['to'] === null
                || $period['from'] != $previous['to'] || $this->sameId($row->familyId, $previous['row']->familyId)) {
                return InvoiceReason::InvalidChain;
            }
            $previous = $period;
        }
        if ($previous['to'] !== null) {
            return InvoiceReason::InvalidChain;
        }
        if (! $this->sameId($previous['row']->familyId, $input->currentFamilyId)) {
            return InvoiceReason::StaleOwner;
        }
        // Bukti audit dihitung dari facts eksplisit, bukan boolean "guard valid" dari caller.
        $expected = [];
        foreach ($rows as $index => $period) {
            $row = $period['row'];
            $expected[strtolower($row->id)][] = [$row->source === 'cutover_capture' ? 'ownership_captured' : 'ownership_opened',
                strtolower($row->recordedBy), BillingTime::format($period['from'])];
            if ($period['to'] !== null) {
                if (! isset($rows[$index + 1])) {
                    return InvoiceReason::InvalidChain;
                }
                $expected[strtolower($row->id)][] = ['ownership_closed', strtolower($rows[$index + 1]['row']->recordedBy), BillingTime::format($period['to'])];
            }
        }
        foreach ($input->ownershipActions as $action) {
            if (! BillingTime::uuid($action->periodId) || ! BillingTime::uuid($action->actorId)) {
                return InvoiceReason::InvalidAudit;
            }
            try {
                $at = BillingTime::format(BillingTime::instant($action->occurredAt));
            } catch (InvoiceCalculationException) {
                return InvoiceReason::InvalidAudit;
            }
            $id = strtolower($action->periodId);
            $match = array_search([$action->action, strtolower($action->actorId), $at], $expected[$id] ?? [], true);
            if ($match === false) {
                return InvoiceReason::InvalidAudit;
            }
            unset($expected[$id][$match]);
        }
        foreach ($expected as $remaining) {
            if ($remaining !== []) {
                return InvoiceReason::InvalidAudit;
            }
        }

        return $rows;
    }

    private function sameId(string $one, string $two): bool
    {
        return BillingTime::uuid($one) && BillingTime::uuid($two) && strtolower($one) === strtolower($two);
    }

    private function hold(InvoiceReason $reason): InvoiceEligibilityResult
    {
        return new InvoiceEligibilityResult(InvoiceDecision::Hold, $reason);
    }

    private function reject(InvoiceReason $reason): InvoiceEligibilityResult
    {
        return new InvoiceEligibilityResult(InvoiceDecision::Reject, $reason);
    }
}
