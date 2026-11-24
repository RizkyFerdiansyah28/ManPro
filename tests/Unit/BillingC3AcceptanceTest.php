<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC3Acceptance;
use Tests\Support\BillingC3Bootstrap;

final class BillingC3AcceptanceTest extends TestCase
{
    public function test_calendar_expectation_uses_wib_and_calendar_days(): void
    {
        self::assertSame(['2028-02-29', '2028-03-03'], BillingC3Acceptance::expectedDates('2028-02-22 16:59:59.999999+00'));
        self::assertSame(['2028-03-01', '2028-03-04'], BillingC3Acceptance::expectedDates('2028-02-22 17:00:00.000000+00'));
        self::assertSame(['2029-01-07', '2029-01-10'], BillingC3Acceptance::expectedDates('2028-12-31 00:00:00+07'));
    }

    public function test_acceptance_controls_do_not_accept_a_free_target_or_extra_arguments(): void
    {
        $invoice = '2b6fb559-bc4d-473f-a9dd-250d35ce38c1';
        $payment = '64db11a5-86be-47af-ac6e-80c579b22824';
        self::assertTrue(BillingC3Bootstrap::validAction(['acceptance']));
        self::assertTrue(BillingC3Bootstrap::validAction(['acceptance-initial']));
        self::assertTrue(BillingC3Bootstrap::validAction(['terminal-context', $invoice]));
        self::assertTrue(BillingC3Bootstrap::validAction(['verdict-freshness', $invoice, $payment]));
        foreach ([
            ['acceptance', $invoice], ['acceptance-initial', $invoice], ['terminal-context', 'manpro_db'],
            ['terminal-context', $invoice, 'other-role'],
            ['verdict-freshness', $invoice], ['verdict-freshness', $invoice, '../../other-root'],
            ['verdict-freshness', $invoice, $payment, 'override'],
        ] as $arguments) {
            self::assertFalse(BillingC3Bootstrap::validAction($arguments));
        }
    }

    public function test_initial_billing_accepts_one_bound_capture_per_target_and_policy_only_events(): void
    {
        [$graph, $sources, $periods, $actions, $policyEvents] = self::initialBillingEvidence();
        self::assertCount(16, $sources);
        self::assertCount(16, $periods);
        self::assertCount(16, $actions);
        BillingC3Acceptance::assertInitialBilling($graph, $sources, array_reverse($periods), $actions, array_reverse($policyEvents));
    }

    #[DataProvider('invalidInitialBillingEvidence')]
    public function test_initial_billing_rejects_invalid_graph_or_ownership(callable $change, string $reason): void
    {
        [$graph, $sources, $periods, $actions, $policyEvents] = self::initialBillingEvidence();
        $change($graph, $sources, $periods, $actions, $policyEvents);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_initial_'.$reason);
        BillingC3Acceptance::assertInitialBilling($graph, $sources, $periods, $actions, $policyEvents);
    }

    public static function invalidInitialBillingEvidence(): array
    {
        return [
            'missing ownership audit' => [static function (&$graph, &$sources, &$periods, &$actions): void {
                array_pop($actions);
                $graph['billing_actions']['rows']--;
            }, 'billing_actions_count_mismatch'],
            'duplicate ownership audit with matching total' => [static function (&$graph, &$sources, &$periods, &$actions): void {
                $actions[1]->ownership_period_id = $actions[0]->ownership_period_id;
            }, 'ownership_action_binding_mismatch'],
            'period for the wrong resident' => [static function (&$graph, &$sources, &$periods): void {
                $periods[0]->elderly_id = 'unexpected-resident';
            }, 'ownership_target_mismatch'],
            'duplicate target period with matching total' => [static function (&$graph, &$sources, &$periods): void {
                $periods[1]->elderly_id = $periods[0]->elderly_id;
            }, 'ownership_target_mismatch'],
            'period family does not match the target' => [static function (&$graph, &$sources, &$periods): void {
                $periods[0]->family_id = $sources['foreign']['family'];
            }, 'ownership_period_mismatch'],
            'resident current owner differs' => [static function (&$graph, &$sources, &$periods): void {
                $periods[0]->current_family_id = $sources['foreign']['family'];
            }, 'ownership_period_mismatch'],
            'ownership starts on the wrong WIB day' => [static function (&$graph, &$sources, &$periods): void {
                $periods[0]->starts_in_wib = '2026-09-02 00:00:00.000000';
            }, 'ownership_period_mismatch'],
            'wrong ownership foreign key' => [static function (&$graph, &$sources, &$periods, &$actions): void {
                $actions[0]->ownership_period_id = 'missing-period';
            }, 'ownership_action_binding_mismatch'],
            'ownership action has the wrong actor' => [static function (&$graph, &$sources, &$periods, &$actions): void {
                $actions[0]->actor_id = $sources['foreign']['family'];
            }, 'ownership_action_binding_mismatch'],
            'invoice audit replaces a capture' => [static function (&$graph, &$sources, &$periods, &$actions): void {
                $actions[0]->action = 'invoice_issued';
                $actions[0]->invoice_id = 'unexpected-invoice';
            }, 'ownership_action_mismatch'],
            'ownership action has a payment link' => [static function (&$graph, &$sources, &$periods, &$actions): void {
                $actions[0]->payment_id = 'unexpected-payment';
            }, 'ownership_action_mismatch'],
            'early invoice' => [static function (&$graph): void {
                $graph['invoices']['rows']++;
            }, 'invoices_count_mismatch'],
            'early payment' => [static function (&$graph): void {
                $graph['payments']['rows']++;
            }, 'payments_count_mismatch'],
            'early financial request' => [static function (&$graph): void {
                $graph['billing_requests']['rows']++;
            }, 'billing_requests_count_mismatch'],
            'unexpected scanner grant replaces policy event' => [static function (&$graph, &$sources, &$periods, &$actions, &$events): void {
                $events[0]->event_type = 'protected_get_granted';
            }, 'policy_events_mismatch'],
            'policy event lacks the active head binding' => [static function (&$graph, &$sources, &$periods, &$actions, &$events): void {
                $events[0]->scope_key = null;
            }, 'policy_event_binding_mismatch'],
            'one missing period with matching target and audit totals' => [static function (&$graph, &$sources, &$periods): void {
                array_pop($periods);
            }, 'ownership_period_count_mismatch'],
            'target count differs from audit count' => [static function (&$graph, &$sources): void {
                unset($sources['foreign']);
            }, 'targets_mismatch'],
            'unexpected target tag with matching target count' => [static function (&$graph, &$sources): void {
                $sources['unexpected'] = $sources['foreign'];
                unset($sources['foreign']);
            }, 'targets_mismatch'],
        ];
    }

    private static function initialBillingEvidence(): array
    {
        $sources = $periods = $actions = [];
        foreach (BillingC3Bootstrap::TARGETS as $index => $tag) {
            $family = $tag === 'foreign' ? 'family-b' : 'family-a';
            $sources[$tag] = ['resident' => 'resident-'.$index, 'family' => $family, 'admin' => 'fixture-admin', 'month' => '2026-10-01'];
            $periods[] = (object) ['id' => 'period-'.$index, 'elderly_id' => $sources[$tag]['resident'],
                'family_id' => $family, 'current_family_id' => $family, 'recorded_by' => $sources[$tag]['admin'],
                'previous_period_id' => null, 'effective_to' => null, 'source' => 'cutover_capture',
                'starts_in_wib' => '2026-09-01 00:00:00.000000', 'recorded_at_start' => true];
            $actions[] = (object) ['action' => 'ownership_captured', 'actor_id' => $sources[$tag]['admin'],
                'ownership_period_id' => 'period-'.$index, 'invoice_id' => null, 'payment_id' => null,
                'billing_request_id' => null, 'revision_id' => null, 'invoice_sequence' => null, 'at_period_start' => true];
        }

        $counts = ['invoices' => 0, 'invoice_revisions' => 0, 'payments' => 0, 'billing_requests' => 0, 'billing_actions' => 16,
            'proof_scan_subjects' => 0, 'proof_scan_jobs' => 0, 'proof_scan_attempts' => 0, 'proof_scan_verdicts' => 0,
            'proof_scan_audit_events' => 2, 'proof_scan_policies' => 1, 'proof_scan_policy_heads' => 1];
        $policyEvents = array_map(static fn ($type) => (object) ['event_type' => $type, 'subject_id' => null,
            'scope_key' => 'academic_owned_run', 'admission' => 'active', 'epoch' => 1], ['policy_created', 'policy_activated']);

        return [array_map(static fn ($count) => ['rows' => $count], $counts), $sources, $periods, $actions, $policyEvents];
    }
}
