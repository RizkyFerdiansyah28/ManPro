<?php

declare(strict_types=1);

use App\Services\Billing\ProofScanReadiness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Prospective completeness only; the existing fresh writer remains fail-closed until IMP-R2-02.
return new class extends Migration
{
    // Off-lock source/filesystem preparation must precede our explicit transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        $readiness = app(ProofScanReadiness::class);
        $readiness->prepareMigration('M11');
        $ddl = $this->definitions();

        DB::transaction(function () use ($readiness, $ddl): void {
            $this->timeouts();
            $readiness->assertMigrationTarget('M11');
            DB::unprepared($ddl);
            $readiness->assertInstalled(true);
        }, 1);
    }

    public function down(): void
    {
        $readiness = app(ProofScanReadiness::class);
        $readiness->prepareMigration('DOWN11');
        $statements = $readiness->removalStatements(true);

        DB::transaction(function () use ($readiness, $statements): void {
            $this->timeouts();
            $readiness->assertMigrationTarget('DOWN11');
            $readiness->assertEmptyForReversal();
            foreach ($statements as $statement) {
                DB::statement($statement);
            }
        }, 1);
    }

    private function timeouts(): void
    {
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::statement("SET LOCAL statement_timeout='15s'");
        DB::statement("SET LOCAL idle_in_transaction_session_timeout='20s'");
    }

    private function definitions(): string
    {
        return <<<'SQL'
        CREATE FUNCTION public.proof_scan_initial_candidate(p_version smallint, p_channel character varying) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN RETURN COALESCE(p_version=1 AND p_channel='family_upload',false); END;
        $fn$;

        CREATE FUNCTION public.proof_scan_validate_initial_payment() RETURNS trigger
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE pay public.payments%ROWTYPE; q public.proof_scan_subjects%ROWTYPE; h public.proof_scan_policy_heads%ROWTYPE;
            j public.proof_scan_jobs%ROWTYPE; r public.billing_requests%ROWTYPE; b public.billing_actions%ROWTYPE; item_count integer;
        BEGIN
            -- Prospective only. No UPDATE/history/backfill; no late row locks or financial mutation.
            SELECT * INTO STRICT pay FROM public.payments WHERE id=NEW.id;
            SELECT count(*) INTO item_count FROM public.proof_scan_subjects WHERE payment_id=pay.id;
            IF item_count<>1 OR pay.status IS DISTINCT FROM 'pending'
               OR NOT public.proof_scan_initial_candidate(pay.billing_schema_version,pay.submission_channel)
               OR NOT EXISTS (SELECT 1 FROM public.invoices i WHERE i.id=pay.invoice_id AND i.billing_schema_version=1
                    AND i.status='unpaid' AND i.current_revision_id=pay.invoice_revision_id AND i.amount=pay.amount AND i.amount>0) THEN
                PERFORM public.proof_scan_fail('pscan_initial_graph_complete');
            END IF;
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE payment_id=pay.id;
            SELECT * INTO h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run';
            SELECT * INTO r FROM public.billing_requests WHERE id=q.billing_request_id;
            SELECT * INTO b FROM public.billing_actions WHERE id=q.billing_action_id;
            IF h.id IS NULL OR h.admission<>'active' OR q.state<>'QUEUED' OR q.generation<>1 OR q.state_version<>1 OR q.security_hold
               OR q.created_at<>q.changed_at OR q.subject_digest<>public.proof_scan_subject_digest(to_jsonb(q))
               OR ROW(q.policy_id,q.policy_epoch,q.admission_incarnation) IS DISTINCT FROM ROW(h.policy_id,h.epoch,h.admission_incarnation)
               OR ROW(q.invoice_id,q.revision_id,q.submitter_id,q.proof_disk,q.proof_path,q.proof_sha256,q.proof_size_bytes,q.proof_mime)
                 IS DISTINCT FROM ROW(pay.invoice_id,pay.invoice_revision_id,pay.submitted_by,pay.proof_disk,pay.proof_path,pay.proof_sha256,pay.proof_size_bytes,pay.proof_mime)
               OR q.proof_reference::text IS DISTINCT FROM split_part(pay.proof_path,'/',2)
               OR r.id IS NULL OR b.id IS NULL
               OR ROW(r.operation,r.invoice_id,r.actor_id,r.outcome_payment_id,r.outcome_revision_id)
                 IS DISTINCT FROM ROW('submit'::varchar,pay.invoice_id,pay.submitted_by,pay.id,pay.invoice_revision_id)
               OR ROW(b.action,b.billing_request_id,b.invoice_id,b.actor_id,b.payment_id,b.revision_id)
                 IS DISTINCT FROM ROW('payment_submitted'::varchar,r.id,pay.invoice_id,pay.submitted_by,pay.id,pay.invoice_revision_id) THEN
                PERFORM public.proof_scan_fail('pscan_initial_graph_complete');
            END IF;
            SELECT count(*) INTO item_count FROM public.proof_scan_jobs WHERE subject_id=q.id;
            IF item_count<>1 OR EXISTS (SELECT 1 FROM public.proof_scan_attempts a JOIN public.proof_scan_jobs x ON x.id=a.job_id WHERE x.subject_id=q.id)
               OR EXISTS (SELECT 1 FROM public.proof_scan_verdicts WHERE subject_id=q.id) THEN
                PERFORM public.proof_scan_fail('pscan_initial_graph_complete');
            END IF;
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE subject_id=q.id;
            IF ROW(j.policy_id,j.policy_epoch,j.admission_incarnation,j.generation) IS DISTINCT FROM ROW(q.policy_id,q.policy_epoch,q.admission_incarnation,q.generation)
               OR j.state<>'READY' OR j.attempt_count<>0 OR j.fence<>1 OR j.current_attempt_id IS NOT NULL
               OR j.stage_id<>'synthetic_stage_v1' OR j.origin<>'fresh_submit' OR j.created_at<>q.created_at
               OR j.changed_at<>q.created_at OR j.not_before<>q.created_at OR j.terminal_reason IS NOT NULL
               OR NOT EXISTS (SELECT 1 FROM public.proof_scan_policies p WHERE p.id=q.policy_id
                    AND j.stage_plan_digest=public.proof_scan_digest('silansia.proof.stage.academic.v1',p.stage_plan))
               OR NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.subject_id=q.id AND e.event_type='initial_subject_bound'
                    AND e.subject_state_version=1 AND e.subject_state='QUEUED' AND e.generation=1 AND e.policy_id=q.policy_id
                    AND e.policy_epoch=q.policy_epoch AND e.admission_incarnation=q.admission_incarnation
                    AND e.descriptor_digest=q.subject_digest AND e.recorded_at=q.created_at AND e.correlation_id=q.id)
               OR NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.subject_id=q.id AND e.event_type='job_created' AND e.job_id=j.id
                    AND e.subject_state_version=1 AND e.subject_state='QUEUED' AND e.generation=1 AND e.fence=1 AND e.policy_id=q.policy_id
                    AND e.policy_epoch=q.policy_epoch AND e.admission_incarnation=q.admission_incarnation
                    AND e.descriptor_digest=q.subject_digest AND e.recorded_at=q.created_at AND e.correlation_id=q.id) THEN
                PERFORM public.proof_scan_fail('pscan_initial_graph_complete');
            END IF;
            RETURN NULL;
        END;
        $fn$;

        CREATE CONSTRAINT TRIGGER proof_scan_payment_initial_final AFTER INSERT ON public.payments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (public.proof_scan_initial_candidate(NEW.billing_schema_version,NEW.submission_channel)) EXECUTE FUNCTION public.proof_scan_validate_initial_payment();

        REVOKE ALL ON FUNCTION public.proof_scan_initial_candidate(smallint, character varying) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_validate_initial_payment() FROM PUBLIC;
        SQL;
    }
};
