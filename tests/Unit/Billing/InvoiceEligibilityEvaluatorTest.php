<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\EnrollmentSnapshot;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDecision;
use App\Services\Billing\InvoiceEligibilityEvaluator;
use App\Services\Billing\InvoiceEligibilityInput;
use App\Services\Billing\InvoiceReason;
use App\Services\Billing\OwnershipActionSnapshot;
use App\Services\Billing\OwnershipPeriodSnapshot;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceEligibilityEvaluatorTest extends TestCase
{
    private function id(int $number): string
    {
        return '00000000-0000-4000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
    }

    private function enrollment(array $changes = []): EnrollmentSnapshot
    {
        return new EnrollmentSnapshot(...array_replace(['id' => $this->id(7), 'elderlyId' => $this->id(1),
            'serviceClassId' => $this->id(6), 'startedAt' => '2028-01-01T00:00:00+07:00',
            'endedAt' => null, 'monthlyPrice' => '3100000.00'], $changes));
    }

    private function period(array $changes = []): OwnershipPeriodSnapshot
    {
        return new OwnershipPeriodSnapshot(...array_replace(['id' => $this->id(8), 'elderlyId' => $this->id(1),
            'familyId' => $this->id(2), 'previousPeriodId' => null, 'effectiveFrom' => '2027-12-01T00:00:00+07:00',
            'effectiveTo' => null, 'recordedBy' => $this->id(5), 'recordedAt' => '2027-12-01T00:00:00+07:00',
            'source' => 'resident_create'], $changes));
    }

    /** Data sintetis eksplisit; tidak ada capture atau penulisan database. */
    private function actions(array $periods): array
    {
        $actions = [];
        foreach ($periods as $index => $period) {
            $actions[] = new OwnershipActionSnapshot($period->id, $period->recordedBy,
                $period->source === 'cutover_capture' ? 'ownership_captured' : 'ownership_opened', $period->recordedAt);
            if ($period->effectiveTo !== null) {
                $actions[] = new OwnershipActionSnapshot($period->id, $periods[$index + 1]->recordedBy,
                    'ownership_closed', $period->effectiveTo);
            }
        }

        return $actions;
    }

    private function input(array $changes = []): InvoiceEligibilityInput
    {
        $periods = [$this->period()];

        return new InvoiceEligibilityInput(...array_replace(['elderlyId' => $this->id(1), 'currentFamilyId' => $this->id(2),
            'residentActive' => true, 'billingMonth' => '2028-01-01', 'asOf' => '2028-03-01T00:00:00+07:00',
            'policyVersion' => InvoiceCalculator::POLICY_VERSION, 'existingInvoiceId' => null,
            'enrollments' => [$this->enrollment()], 'ownershipPeriods' => $periods, 'ownershipActions' => $this->actions($periods)], $changes));
    }

    private function transferChain(string $at, ?string $returnAt = null): array
    {
        $periods = [$this->period(['effectiveTo' => $at]),
            $this->period(['id' => $this->id(9), 'familyId' => $this->id(3), 'previousPeriodId' => $this->id(8),
                'effectiveFrom' => $at, 'recordedAt' => $at, 'effectiveTo' => $returnAt, 'source' => 'family_transfer'])];
        if ($returnAt !== null) {
            $periods[] = $this->period(['id' => $this->id(10), 'previousPeriodId' => $this->id(9),
                'effectiveFrom' => $returnAt, 'recordedAt' => $returnAt, 'source' => 'family_transfer']);
        }

        return $periods;
    }

    public static function validCases(): array
    {
        return [['active', '3100000.00'], ['inactive', '3100000.00'], ['closed', '1000000.00'],
            ['no-stay', '3100000.00'], ['zero', '0.00'], ['rounded-zero', '0.00'], ['exact-month-end', '3100000.00'],
            ['cutover-before-service', '1200000.00'], ['closed-inactive', '1000000.00']];
    }

    #[DataProvider('validCases')]
    public function test_valid_snapshots_issue_domain_decision_without_issuing_invoice(string $case, string $amount): void
    {
        $changes = [];
        if (in_array($case, ['inactive', 'closed-inactive'], true)) {
            $changes['residentActive'] = false;
        }
        if (in_array($case, ['closed', 'closed-inactive'], true)) {
            $changes['enrollments'] = [$this->enrollment(['endedAt' => '2028-01-11T00:00:00+07:00'])];
        } elseif ($case === 'zero') {
            $changes['enrollments'] = [$this->enrollment(['monthlyPrice' => '0.00'])];
        } elseif ($case === 'rounded-zero') {
            $changes['enrollments'] = [$this->enrollment(['monthlyPrice' => '0.01', 'startedAt' => '2028-01-31T00:00:00+07:00'])];
        } elseif ($case === 'exact-month-end') {
            $changes['asOf'] = '2028-01-31T17:00:00.000000Z';
        } elseif ($case === 'cutover-before-service') {
            $periods = [$this->period(['effectiveFrom' => '2028-01-15T12:00:00+07:00',
                'recordedAt' => '2028-01-15T12:00:00+07:00', 'source' => 'cutover_capture'])];
            $changes = ['enrollments' => [$this->enrollment(['startedAt' => '2028-01-20T00:00:00+07:00'])],
                'ownershipPeriods' => $periods, 'ownershipActions' => $this->actions($periods)];
        }
        $input = $this->input($changes);
        $before = serialize($input);
        $result = (new InvoiceEligibilityEvaluator)->evaluate($input);
        $this->assertSame(InvoiceDecision::Issue, $result->decision);
        $this->assertSame(InvoiceReason::Eligible, $result->reason);
        $this->assertSame($amount, $result->calculation->amount);
        $this->assertSame($this->id(2), $result->financialOwnerId);
        $this->assertSame($this->id(8), $result->ownershipPeriodId);
        $this->assertSame($before, serialize($input));
        // Tidak ada actor/Policy, stay, master price, status paid atau nomor invoice di kontrak ini.
        $this->assertFalse(property_exists($result, 'paidAt'));
        $this->assertFalse(property_exists($result, 'invoiceNumber'));
    }

    public static function blockedCases(): array
    {
        return [
            ['no-enrollment', InvoiceDecision::Skip, InvoiceReason::NoService],
            ['future-enrollment', InvoiceDecision::Skip, InvoiceReason::NoService],
            ['past-enrollment', InvoiceDecision::Skip, InvoiceReason::NoService],
            ['missing-enrollments', InvoiceDecision::Hold, InvoiceReason::IncompleteEvidence],
            ['adjacent', InvoiceDecision::Hold, InvoiceReason::MultipleEnrollments],
            ['overlap', InvoiceDecision::Hold, InvoiceReason::MultipleEnrollments],
            ['class-change', InvoiceDecision::Hold, InvoiceReason::MultipleEnrollments],
            ['null-rate', InvoiceDecision::Hold, InvoiceReason::NullRate],
            ['invalid-rate', InvoiceDecision::Hold, InvoiceReason::InvalidRate],
            ['float-rate', InvoiceDecision::Hold, InvoiceReason::InvalidRate],
            ['missing-ledger', InvoiceDecision::Hold, InvoiceReason::MissingLedger],
            ['unknown-ledger', InvoiceDecision::Hold, InvoiceReason::IncompleteEvidence],
            ['unknown-audit', InvoiceDecision::Hold, InvoiceReason::IncompleteEvidence],
            ['partial-coverage', InvoiceDecision::Hold, InvoiceReason::PartialCoverage],
            ['microsecond-gap', InvoiceDecision::Hold, InvoiceReason::PartialCoverage],
            ['stale-leaf', InvoiceDecision::Hold, InvoiceReason::StaleOwner],
            ['duplicate-period', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['missing-audit', InvoiceDecision::Hold, InvoiceReason::InvalidAudit],
            ['duplicate-audit', InvoiceDecision::Hold, InvoiceReason::InvalidAudit],
            ['wrong-audit-actor', InvoiceDecision::Hold, InvoiceReason::InvalidAudit],
            ['wrong-audit-time', InvoiceDecision::Hold, InvoiceReason::InvalidAudit],
            ['recorded-time-mismatch', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['future-ledger', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['wrong-resident-period', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['wrong-resident-enrollment', InvoiceDecision::Hold, InvoiceReason::InvalidEnrollment],
            ['duplicate-enrollment', InvoiceDecision::Hold, InvoiceReason::InvalidEnrollment],
            ['invalid-enrollment-interval', InvoiceDecision::Hold, InvoiceReason::InvalidEnrollment],
            ['ambiguous-enrollment-time', InvoiceDecision::Hold, InvoiceReason::InvalidEnrollment],
            ['invalid-month', InvoiceDecision::Reject, InvoiceReason::InvalidMonth],
            ['future-month', InvoiceDecision::Reject, InvoiceReason::MonthNotEnded],
            ['before-month-end-microsecond', InvoiceDecision::Reject, InvoiceReason::MonthNotEnded],
            ['invalid-clock', InvoiceDecision::Reject, InvoiceReason::InvalidClock],
            ['existing-root', InvoiceDecision::Reject, InvoiceReason::ExistingInvoice],
            ['invalid-command', InvoiceDecision::Reject, InvoiceReason::InvalidCommand],
            ['invalid-existing-root-id', InvoiceDecision::Reject, InvoiceReason::InvalidCommand],
            ['unsupported-policy', InvoiceDecision::Reject, InvoiceReason::UnsupportedPolicy],
        ];
    }

    #[DataProvider('blockedCases')]
    public function test_each_negative_case_starts_valid_and_has_exact_decision_without_amount(string $case, InvoiceDecision $decision, InvoiceReason $reason): void
    {
        $evaluator = new InvoiceEligibilityEvaluator;
        $this->assertSame(InvoiceDecision::Issue, $evaluator->evaluate($this->input())->decision);
        $period = $this->period();
        $audit = $this->actions([$period]);
        $changes = match ($case) {
            'no-enrollment' => ['enrollments' => []],
            'future-enrollment' => ['enrollments' => [$this->enrollment(['startedAt' => '2028-02-01T00:00:00+07:00', 'monthlyPrice' => null])]],
            'past-enrollment' => ['enrollments' => [$this->enrollment(['startedAt' => '2027-12-01T00:00:00+07:00', 'endedAt' => '2028-01-01T00:00:00+07:00'])]],
            'missing-enrollments' => ['enrollments' => null],
            'adjacent', 'class-change' => ['enrollments' => [$this->enrollment(['endedAt' => '2028-01-15T00:00:00+07:00']),
                $this->enrollment(['id' => $this->id(20), 'serviceClassId' => $case === 'class-change' ? $this->id(30) : $this->id(6), 'startedAt' => '2028-01-15T00:00:00+07:00'])]],
            'overlap' => ['enrollments' => [$this->enrollment(), $this->enrollment(['id' => $this->id(20)])]],
            'null-rate', 'invalid-rate', 'float-rate' => ['enrollments' => [$this->enrollment(['monthlyPrice' => match ($case) {
                'null-rate' => null, 'float-rate' => 1.25, default => 'NaN'
            }])]],
            'missing-ledger' => ['ownershipPeriods' => [], 'ownershipActions' => []],
            'unknown-ledger' => ['ownershipPeriods' => null],
            'unknown-audit' => ['ownershipActions' => null],
            'partial-coverage', 'microsecond-gap', 'recorded-time-mismatch', 'future-ledger', 'wrong-resident-period' => [],
            'stale-leaf' => ['currentFamilyId' => $this->id(3)],
            'duplicate-period' => ['ownershipPeriods' => [$period, $period]],
            'missing-audit' => ['ownershipActions' => []],
            'duplicate-audit' => ['ownershipActions' => [$audit[0], $audit[0]]],
            'wrong-audit-actor' => ['ownershipActions' => [new OwnershipActionSnapshot($period->id, $this->id(3), 'ownership_opened', $period->recordedAt)]],
            'wrong-audit-time' => ['ownershipActions' => [new OwnershipActionSnapshot($period->id, $period->recordedBy, 'ownership_opened', '2027-12-01T00:00:00.000001+07:00')]],
            'wrong-resident-enrollment' => ['enrollments' => [$this->enrollment(['elderlyId' => $this->id(3)])]],
            'duplicate-enrollment' => ['enrollments' => [$this->enrollment(), $this->enrollment()]],
            'invalid-enrollment-interval' => ['enrollments' => [$this->enrollment(['endedAt' => '2028-01-01T00:00:00+07:00'])]],
            'ambiguous-enrollment-time' => ['enrollments' => [$this->enrollment(['startedAt' => '2028-01-01 00:00:00'])]],
            'invalid-month' => ['billingMonth' => '2028-01-02'],
            'future-month' => ['billingMonth' => '2028-03-01'],
            'before-month-end-microsecond' => ['asOf' => '2028-01-31T16:59:59.999999Z'],
            'invalid-clock' => ['asOf' => 'now'],
            'existing-root' => ['existingInvoiceId' => $this->id(99)],
            'invalid-command' => ['elderlyId' => 'invalid'],
            'invalid-existing-root-id' => ['existingInvoiceId' => 'invalid'],
            'unsupported-policy' => ['policyVersion' => 'future-v2'],
        };
        if (in_array($case, ['partial-coverage', 'microsecond-gap', 'recorded-time-mismatch', 'future-ledger', 'wrong-resident-period'], true)) {
            $override = match ($case) {
                'partial-coverage' => ['effectiveFrom' => '2028-01-15T00:00:00+07:00', 'recordedAt' => '2028-01-15T00:00:00+07:00'],
                'microsecond-gap' => ['effectiveFrom' => '2028-01-01T00:00:00.000001+07:00', 'recordedAt' => '2028-01-01T00:00:00.000001+07:00'],
                'recorded-time-mismatch' => ['recordedAt' => '2027-12-01T00:00:00.000001+07:00'],
                'future-ledger' => ['effectiveFrom' => '2028-04-01T00:00:00+07:00', 'recordedAt' => '2028-04-01T00:00:00+07:00'],
                'wrong-resident-period' => ['elderlyId' => $this->id(3)],
            };
            $periods = [$this->period($override)];
            $changes = ['ownershipPeriods' => $periods, 'ownershipActions' => $this->actions($periods)];
        }
        $input = $this->input($changes);
        $before = serialize($input);
        $result = $evaluator->evaluate($input);
        $this->assertSame($decision, $result->decision);
        $this->assertSame($reason, $result->reason);
        $this->assertNull($result->calculation);
        $this->assertNull($result->financialOwnerId);
        $this->assertNull($result->ownershipPeriodId);
        $this->assertSame($before, serialize($input));
    }

    public static function ownershipCases(): array
    {
        return [['mixed-after-close', InvoiceDecision::Hold, InvoiceReason::MixedOwners],
            ['return-to-original', InvoiceDecision::Hold, InvoiceReason::MixedOwners],
            ['at-m0', InvoiceDecision::Issue, InvoiceReason::Eligible],
            ['at-m1', InvoiceDecision::Issue, InvoiceReason::Eligible],
            ['gap', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['overlap', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['fork', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['same-family-successor', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['closed-without-successor', InvoiceDecision::Hold, InvoiceReason::InvalidChain],
            ['wrong-close-actor', InvoiceDecision::Hold, InvoiceReason::InvalidAudit]];
    }

    #[DataProvider('ownershipCases')]
    public function test_full_chain_and_k01_boundaries(string $case, InvoiceDecision $decision, InvoiceReason $reason): void
    {
        $at = match ($case) {
            'at-m0' => '2028-01-01T00:00:00+07:00', 'at-m1' => '2028-02-01T00:00:00+07:00', default => '2028-01-20T00:00:00+07:00'
        };
        $periods = $this->transferChain($at, $case === 'return-to-original' ? '2028-01-25T00:00:00+07:00' : null);
        $current = $case === 'return-to-original' ? $this->id(2) : $this->id(3);
        if (in_array($case, ['gap', 'overlap', 'same-family-successor'], true)) {
            $nextAt = $case === 'gap' ? '2028-01-20T00:00:00.000001+07:00' : '2028-01-19T23:59:59.999999+07:00';
            $periods[1] = $this->period(['id' => $this->id(9), 'familyId' => $case === 'same-family-successor' ? $this->id(2) : $this->id(3),
                'previousPeriodId' => $this->id(8), 'source' => 'family_transfer',
                'effectiveFrom' => $case === 'same-family-successor' ? $at : $nextAt, 'recordedAt' => $case === 'same-family-successor' ? $at : $nextAt]);
        } elseif ($case === 'fork') {
            $periods = $this->transferChain($at, '2028-01-25T00:00:00+07:00');
            $periods[2] = $this->period(['id' => $this->id(10), 'previousPeriodId' => $this->id(8), 'source' => 'family_transfer',
                'effectiveFrom' => '2028-01-25T00:00:00+07:00', 'recordedAt' => '2028-01-25T00:00:00+07:00']);
            $current = $this->id(2);
        }
        $actions = $this->actions($periods);
        if ($case === 'closed-without-successor') {
            $periods = [$periods[0]];
            $actions = [$actions[0], $actions[1]];
        } elseif ($case === 'wrong-close-actor') {
            $actions[1] = new OwnershipActionSnapshot($periods[0]->id, $this->id(3), 'ownership_closed', $at);
        }
        $input = $this->input(['currentFamilyId' => $current, 'ownershipPeriods' => $periods, 'ownershipActions' => $actions,
            'enrollments' => [$this->enrollment(['endedAt' => '2028-01-16T00:00:00+07:00'])]]);
        $result = (new InvoiceEligibilityEvaluator)->evaluate($input);
        $this->assertSame($decision, $result->decision);
        $this->assertSame($reason, $result->reason);
        if ($decision === InvoiceDecision::Issue) {
            $this->assertSame($case === 'at-m0' ? $this->id(3) : $this->id(2), $result->financialOwnerId);
        } else {
            $this->assertNull($result->calculation);
        }
    }

    public function test_deterministic_order_independent_and_deeply_immutable_snapshots(): void
    {
        $periods = $this->transferChain('2028-02-01T00:00:00+07:00');
        $actions = $this->actions($periods);
        $input = $this->input(['currentFamilyId' => $this->id(3), 'ownershipPeriods' => $periods, 'ownershipActions' => $actions]);
        $reversed = $this->input(['currentFamilyId' => $this->id(3), 'ownershipPeriods' => array_reverse($periods), 'ownershipActions' => array_reverse($actions)]);
        $evaluator = new InvoiceEligibilityEvaluator;
        $before = serialize($input);
        $one = $evaluator->evaluate($input);
        $this->assertSame(InvoiceDecision::Issue, $one->decision);
        $this->assertEquals($one, $evaluator->evaluate($reversed));
        $this->assertEquals($one, $evaluator->evaluate($input));
        $this->assertSame($before, serialize($input));
        foreach (['array', 'element', 'result'] as $target) {
            try {
                match ($target) {
                    'array' => $input->ownershipPeriods[0] = $this->period(),
                    'element' => $input->ownershipPeriods[0]->familyId = $this->id(9),
                    'result' => $one->financialOwnerId = $this->id(9),
                };
                $this->fail('Snapshot/result harus immutable.');
            } catch (Error $exception) {
                $this->assertStringContainsString('readonly', $exception->getMessage());
            }
        }
    }

    public function test_untyped_mutable_or_guard_boolean_evidence_is_not_a_snapshot(): void
    {
        foreach (['enrollments', 'ownershipPeriods', 'ownershipActions'] as $list) {
            try {
                $this->input([$list => [(object) ['valid' => true]]]);
                $this->fail('Boolean valid bukan bukti integritas.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('DTO immutable', $exception->getMessage());
            }
        }
    }

    public function test_runtime_failure_is_rejected_without_returning_any_estimate(): void
    {
        $calculator = new class extends InvoiceCalculator
        {
            protected function integerBytes(): int
            {
                return 4;
            }
        };
        $result = (new InvoiceEligibilityEvaluator($calculator))->evaluate($this->input());
        $this->assertSame(InvoiceDecision::Reject, $result->decision);
        $this->assertSame(InvoiceReason::UnsupportedRuntime, $result->reason);
        $this->assertNull($result->calculation);
    }

    public function test_snapshot_lists_cannot_use_missing_indexes_as_an_implicit_completeness_claim(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->input(['enrollments' => [1 => $this->enrollment()]]);
    }

    public function test_audit_facts_are_compared_as_instants_and_uuid_case_does_not_change_owner(): void
    {
        $period = $this->period(['id' => 'abcdefab-1234-4000-8000-000000000008',
            'familyId' => 'abcdefab-1234-4000-8000-000000000002']);
        $input = $this->input(['currentFamilyId' => strtoupper($period->familyId), 'ownershipPeriods' => [$period],
            'ownershipActions' => [new OwnershipActionSnapshot(strtoupper($period->id), $period->recordedBy,
                'ownership_opened', '2027-11-30T17:00:00.000000Z')]]);
        $result = (new InvoiceEligibilityEvaluator)->evaluate($input);
        $this->assertSame(InvoiceDecision::Issue, $result->decision);
        $this->assertSame($period->familyId, $result->financialOwnerId);
        $this->assertSame($period->id, $result->ownershipPeriodId);
    }

    public function test_earliest_ad_year_uses_original_instants_without_reparsing_normalized_utc_year(): void
    {
        $start = '0001-01-01T00:00:00+07:00';
        $period = $this->period(['effectiveFrom' => $start, 'recordedAt' => $start]);
        $input = $this->input(['billingMonth' => '0001-01-01', 'asOf' => '0001-02-01T00:00:00+07:00',
            'enrollments' => [$this->enrollment(['startedAt' => $start])], 'ownershipPeriods' => [$period],
            'ownershipActions' => $this->actions([$period])]);
        $result = (new InvoiceEligibilityEvaluator)->evaluate($input);
        $this->assertSame(InvoiceDecision::Issue, $result->decision);
        $this->assertSame('3100000.00', $result->calculation->amount);
        $this->assertSame(31, $result->calculation->chargeableDays);
    }
}
