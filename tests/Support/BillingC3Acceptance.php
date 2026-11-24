<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Billing\PaymentProofEnvironment;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Tests\Support\PaymentR2IntegrationObserver as Observer;

final class BillingC3Acceptance
{
    private const GRAPH = ['invoices', 'invoice_revisions', 'payments', 'billing_requests', 'billing_actions',
        'proof_scan_subjects', 'proof_scan_jobs', 'proof_scan_attempts', 'proof_scan_verdicts',
        'proof_scan_audit_events', 'proof_scan_policies', 'proof_scan_policy_heads'];

    public static function snapshot(PostgresTestGuard $guard, bool $initial = false): array
    {
        $sources = $initial ? BillingC3Fixtures::sources() : null;
        $graph = Observer::read(static function (Connection $db) use ($sources): array {
            $facts = [];
            foreach (self::GRAPH as $table) {
                $facts[$table] = (array) $db->selectOne('SELECT count(*) AS rows, encode(sha256(convert_to('
                    ."COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text COLLATE \"C\"),'[]'::jsonb)::text,'UTF8')),"
                    ."'hex') AS digest FROM public.".$table.' t');
            }
            if ($sources !== null) {
                $periods = $db->table('financial_ownership_periods as p')
                    ->leftJoin('elderlies as e', 'e.id', '=', 'p.elderly_id')
                    ->select(['p.id', 'p.elderly_id', 'p.family_id', 'p.recorded_by', 'p.previous_period_id',
                        'p.effective_to', 'p.source', 'e.family_id as current_family_id'])
                    ->selectRaw("to_char(p.effective_from AT TIME ZONE 'Asia/Jakarta', 'YYYY-MM-DD HH24:MI:SS.US') as starts_in_wib,
                        p.recorded_at = p.effective_from as recorded_at_start")->get()->all();
                $actions = $db->table('billing_actions as a')
                    ->leftJoin('financial_ownership_periods as p', 'p.id', '=', 'a.ownership_period_id')
                    ->select(['a.action', 'a.actor_id', 'a.ownership_period_id', 'a.invoice_id', 'a.payment_id',
                        'a.billing_request_id', 'a.revision_id', 'a.invoice_sequence'])
                    ->selectRaw('a.occurred_at = p.effective_from as at_period_start')->get()->all();
                $policyEvents = $db->table('proof_scan_audit_events as a')
                    ->leftJoin('proof_scan_policy_heads as h', 'h.policy_id', '=', 'a.policy_id')
                    ->get(['a.event_type', 'a.subject_id', 'h.scope_key', 'h.admission', 'h.epoch'])->all();
                self::assertInitialBilling($facts, $sources, $periods, $actions, $policyEvents);
            }

            return $facts;
        }, $guard);
        $storage = self::storage(app(PaymentProofEnvironment::class)->root());
        Identity::require(! $initial || $storage === [], 'billing_c3_initial_proof_storage_not_empty');

        return ['graph' => $graph, 'storage' => $storage];
    }

    public static function assertInitialBilling(array $graph, array $sources, array $periods, array $actions, array $policyEvents): void
    {
        $targetCount = count(BillingC3Bootstrap::TARGETS);
        $targets = array_column($sources, null, 'resident');
        Identity::require(count($sources) === $targetCount && count($targets) === $targetCount
            && array_diff(BillingC3Bootstrap::TARGETS, array_keys($sources)) === [], 'billing_c3_initial_targets_mismatch');

        foreach (self::GRAPH as $table) {
            $expected = match ($table) {
                'billing_actions' => $targetCount,
                'proof_scan_policies', 'proof_scan_policy_heads' => 1,
                'proof_scan_audit_events' => 2,
                default => 0,
            };
            Identity::require(($graph[$table]['rows'] ?? null) === $expected, 'billing_c3_initial_'.$table.'_count_mismatch');
        }

        Identity::require(count($periods) === $targetCount, 'billing_c3_initial_ownership_period_count_mismatch');
        $periodActors = [];
        foreach ($periods as $period) {
            $target = $targets[$period->elderly_id] ?? null;
            Identity::require($target !== null && ! isset($periodActors[$period->id]), 'billing_c3_initial_ownership_target_mismatch');
            // The fixture opens its ownership period one calendar month before the billing month in WIB.
            $start = (new DateTimeImmutable($target['month'].'T00:00:00+07:00'))->modify('-1 month');
            Identity::require($period->source === 'cutover_capture' && $period->previous_period_id === null
                && $period->effective_to === null && $period->family_id === $target['family']
                && $period->current_family_id === $target['family'] && $period->recorded_by === $target['admin']
                && $period->starts_in_wib === $start->format('Y-m-d H:i:s.u') && $period->recorded_at_start === true,
                'billing_c3_initial_ownership_period_mismatch');
            $periodActors[$period->id] = $target['admin'];
            unset($targets[$period->elderly_id]);
        }

        Identity::require(count($actions) === $targetCount, 'billing_c3_initial_billing_actions_count_mismatch');
        foreach ($actions as $action) {
            Identity::require($action->action === 'ownership_captured' && $action->invoice_id === null
                && $action->payment_id === null && $action->billing_request_id === null && $action->revision_id === null
                && $action->invoice_sequence === null, 'billing_c3_initial_ownership_action_mismatch');
            Identity::require(isset($periodActors[$action->ownership_period_id])
                && $action->actor_id === $periodActors[$action->ownership_period_id] && $action->at_period_start === true,
                'billing_c3_initial_ownership_action_binding_mismatch');
            unset($periodActors[$action->ownership_period_id]);
        }

        $eventTypes = array_column($policyEvents, 'event_type');
        sort($eventTypes);
        Identity::require($eventTypes === ['policy_activated', 'policy_created'], 'billing_c3_initial_policy_events_mismatch');
        foreach ($policyEvents as $event) {
            Identity::require($event->subject_id === null && $event->scope_key === 'academic_owned_run'
                && $event->admission === 'active' && $event->epoch === 1, 'billing_c3_initial_policy_event_binding_mismatch');
        }
    }

    private static function storage(string $root): array
    {
        Runtime::path($root);
        if (! file_exists($root) && ! is_link($root)) {
            return [];
        }
        BillingC3Bootstrap::assertCanonical($root, true);
        $entries = scandir($root);
        Identity::require(is_array($entries), 'billing_c3_proof_inventory_unreadable');
        $objects = [];
        foreach (array_diff($entries, ['.', '..']) as $reference) {
            Identity::require(preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $reference) === 1,
                'billing_c3_proof_inventory_unexpected_entry');
            $directory = $root.DIRECTORY_SEPARATOR.$reference;
            BillingC3Bootstrap::assertCanonical($directory, true);
            $files = scandir($directory);
            Identity::require(is_array($files), 'billing_c3_proof_inventory_unreadable');
            $contents = array_values(array_diff($files, ['.', '..']));
            Identity::require(in_array($contents, [['lock', 'object.png', 'registry.json'], ['lock', 'object.jpg', 'registry.json']], true),
                'billing_c3_proof_inventory_incomplete');
            foreach ($contents as $name) {
                $path = $directory.DIRECTORY_SEPARATOR.$name;
                BillingC3Bootstrap::assertCanonical($path);
                $digest = hash_file('sha256', $path);
                Identity::require(is_string($digest), 'billing_c3_proof_inventory_unreadable');
                $objects[$reference][$name] = $digest;
            }
        }

        return $objects;
    }

    public static function protection(string $invoice, string $payment, PostgresTestGuard $guard): array
    {
        return Observer::read(static function (Connection $db) use ($invoice, $payment): array {
            $rows = $db->select("SELECT a.id,a.principal_ref AS actor,q.generation=a.generation
                AND q.subject_digest=a.descriptor_digest AND q.policy_id=a.policy_id AS bound
                FROM proof_scan_audit_events a JOIN proof_scan_subjects q ON q.id=a.subject_id
                WHERE q.invoice_id=? AND q.payment_id=? AND a.event_type='protected_get_granted'", [$invoice, $payment]);

            return array_column($rows, null, 'id');
        }, $guard);
    }

    public static function expectedDates(string $issuedAt): array
    {
        $day = (new DateTimeImmutable($issuedAt))->setTimezone(new DateTimeZone('Asia/Jakarta'));

        return [$day->modify('+7 days')->format('Y-m-d'), $day->modify('+10 days')->format('Y-m-d')];
    }

    public static function verdictFreshness(string $invoice, string $payment, PostgresTestGuard $guard): array
    {
        Identity::require(BillingC3Fixtures::target($invoice) === 'ttl', 'billing_c3_ttl_target_invalid');

        return Observer::read(static function (Connection $db) use ($invoice, $payment): array {
            $row = $db->selectOne("SELECT q.generation,j.id AS job,q.state,v.verdict,
                GREATEST(0,ceil(extract(epoch FROM (v.received_at + interval '120 seconds' - clock_timestamp()))*1000))::int AS remaining_ms,
                public.proof_scan_projection(q.id,clock_timestamp(),false) AS projection
                FROM proof_scan_subjects q JOIN proof_scan_jobs j ON j.subject_id=q.id AND j.generation=q.generation
                JOIN proof_scan_verdicts v ON v.attempt_id=j.current_attempt_id AND v.generation=q.generation
                WHERE q.invoice_id=? AND q.payment_id=? AND j.state='COMPLETED' AND v.fence=j.fence", [$invoice, $payment]);
            Identity::require($row !== null && $row->state === 'CLEAN_ELIGIBLE' && $row->verdict === 'clean',
                'billing_c3_ttl_graph_mismatch');

            return ['generation' => $row->generation, 'job' => $row->job, 'remaining_ms' => $row->remaining_ms, 'projection' => $row->projection];
        }, $guard);
    }
}
