<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

final class PaymentR2IntegrationObserver
{
    public static function read(Closure $operation, ?PostgresTestGuard $guard = null): mixed
    {
        $guard ??= PaymentR2IntegrationRuntime::validate(true);
        $primary = DB::connection();
        $config = $primary->getConfig();
        $observer = new PostgresConnection((new PostgresConnector)->connect($config), $config['database'], '', $config);
        try {
            $guard->assertSafe(app(), $observer);
            $primaryPid = $primary->scalar('SELECT pg_backend_pid()');
            $role = $observer->selectOne("SELECT pg_backend_pid() AS pid,session_user::text AS role,
                current_setting('server_version_num')::int AS version");
            Identity::require($role->pid !== $primaryPid && $role->role === $guard->identity['owner']
                && $role->version >= 180000 && $role->version < 190000, 'observer_backend_not_independent');

            return $observer->transaction(function () use ($observer, $operation) {
                $observer->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $observer->statement("SET LOCAL statement_timeout='5s'");
                $observer->statement("SET LOCAL lock_timeout='3s'");
                $observer->statement("SET LOCAL idle_in_transaction_session_timeout='8s'");

                return $operation($observer);
            }, 1);
        } finally {
            $observer->disconnect();
        }
    }

    public static function snapshot(string $invoice, ?PostgresTestGuard $c3Guard = null): array
    {
        if ($c3Guard === null) {
            PaymentR2IntegrationFixtures::target($invoice);
        } else {
            BillingC3Fixtures::target($invoice);
        }

        return self::read(static function (Connection $db) use ($invoice, $c3Guard): array {
            $financial = [
                'invoice' => $db->table('invoices')->where('id', $invoice)
                    ->sole(['status', 'amount', 'current_revision_id', 'bill_to_family_id', ...($c3Guard === null ? [] : ['paid_at'])]),
                'payments' => $db->table('payments')->where('invoice_id', $invoice)->orderBy('id')->get(['id', 'status', 'amount', 'invoice_revision_id']),
                'requests' => $db->table('billing_requests')->where('invoice_id', $invoice)->orderBy('id')
                    ->get(['id', 'actor_id', 'operation', 'outcome_payment_id', 'outcome_revision_id']),
                'revisions' => $db->table('invoice_revisions')->where('invoice_id', $invoice)->orderBy('revision_no')->get(['id', 'revision_no', 'amount']),
            ];
            $actions = $db->table('billing_actions')->where('invoice_id', $invoice)->orderBy('invoice_sequence')
                ->select(['action', 'actor_id', 'payment_id', 'revision_id', 'billing_request_id'])
                ->selectRaw("COALESCE(private_note = 'CATATAN-PRIVAT-SINTETIS-R2',false) AS synthetic_note_matches")->get();
            $audit = $db->select("SELECT a.event_type,a.principal_ref AS actor,q.payment_id,a.generation,a.fence,a.subject_state_version,
                 COALESCE(a.authorization_binding_digest ~ '^[0-9a-f]{64}$' AND a.audience_ref ~ '^[0-9a-f]{64}$',false) AS bound,
                 a.descriptor_digest=q.subject_digest AS descriptor_bound,p.submitted_by,
                 a.policy_id=q.policy_id AND a.admission_incarnation=q.admission_incarnation AS policy_bound
                FROM proof_scan_audit_events a JOIN proof_scan_subjects q ON q.id=a.subject_id
                JOIN payments p ON p.id=q.payment_id WHERE q.invoice_id=? ORDER BY a.recorded_at,a.id", [$invoice]);
            $scanner = [
                'policies' => $db->table('proof_scan_policies')->orderBy('id')->get(['id', 'version']),
                'head' => $db->table('proof_scan_policy_heads')->first(['policy_id', 'epoch as policy_epoch', 'admission', 'admission_incarnation']),
                'graph' => $db->select("SELECT q.payment_id,q.revision_id,q.state,q.generation,q.state_version,q.policy_id,q.policy_epoch,
                    j.id AS job_id,j.generation AS job_generation,j.fence,j.state AS job_state,j.stage_id,
                    a.id AS attempt_id,a.state AS attempt_state,a.generation AS attempt_generation,a.fence AS attempt_fence,
                    v.verdict,v.generation AS verdict_generation,v.fence AS verdict_fence,
                    q.proof_sha256=p.proof_sha256 AND q.proof_size_bytes=p.proof_size_bytes AND q.proof_mime=p.proof_mime
                        AND q.proof_path=p.proof_path AND q.proof_disk=p.proof_disk AND q.revision_id=p.invoice_revision_id AS descriptor_bound,
                    COALESCE(v.subject_digest=q.subject_digest AND v.observed_sha256=q.proof_sha256
                        AND v.observed_size_bytes=q.proof_size_bytes AND v.observed_mime=q.proof_mime,false) AS verdict_bound
                    FROM proof_scan_subjects q JOIN payments p ON p.id=q.payment_id
                    LEFT JOIN proof_scan_jobs j ON j.subject_id=q.id
                    LEFT JOIN proof_scan_attempts a ON a.id=j.current_attempt_id
                    LEFT JOIN proof_scan_verdicts v ON v.attempt_id=a.id WHERE q.invoice_id=?
                    ORDER BY q.payment_id,j.generation,j.id", [$invoice]),
            ];
            $protected = [];
            foreach (['elderlies', 'service_enrollments', 'financial_ownership_periods', 'service_classes', 'rooms', 'cameras',
                'camera_operating_periods', 'elderly_stays', 'ai_detections', 'gait_observations', 'fall_incidents', 'fall_incident_actions',
                'notifications', 'migrations'] as $table) {
                $protected[$table] = $db->scalar('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'
                    .'\'[]\'::jsonb)::text) FROM public."'.$table.'" t');
            }

            return ['financial' => $financial, 'audit' => ['actions' => $actions, 'proof' => $audit],
                'scanner' => $scanner, 'protected' => $protected];
        }, $c3Guard);
    }

    public static function visibility(string $payment, string $kind, ?PostgresTestGuard $c3Guard = null): array
    {
        return self::read(static function (Connection $db) use ($payment, $kind): array {
            $row = $db->selectOne("SELECT p.status AS payment,i.status AS invoice,
                p.xmin::text AS payment_xid,i.xmin::text AS invoice_xid FROM payments p
                JOIN invoices i ON i.id=p.invoice_id WHERE p.id=?::uuid", [$payment]);
            Identity::require($row !== null, 'observer_missing_payment');
            $audit = $db->select("SELECT a.xmin::text AS xid FROM proof_scan_audit_events a
                JOIN proof_scan_subjects q ON q.id=a.subject_id WHERE q.payment_id=?::uuid AND a.event_type=?", [$payment, $kind]);
            $request = $db->select("SELECT r.xmin::text AS xid,b.xmin::text AS action_xid FROM billing_requests r
                JOIN billing_actions b ON b.billing_request_id=r.id AND b.action='payment_verified'
                WHERE r.outcome_payment_id=?::uuid AND r.operation='verify'", [$payment]);
            $same = count($audit) === 1 && count($request) === 1 && $audit[0]->xid === $request[0]->xid
                && $audit[0]->xid === $request[0]->action_xid && $audit[0]->xid === $row->payment_xid && $audit[0]->xid === $row->invoice_xid;

            return ['payment' => $row->payment, 'invoice' => $row->invoice, 'grants' => count($audit),
                'verify_facts' => count($request), 'same_commit' => $same];
        }, $c3Guard);
    }
}
