<?php

declare(strict_types=1);

use App\Services\Billing\ProofScanReadiness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Academic technical metadata only; no policy rows, worker, activation or financial DML.
return new class extends Migration
{
    // Off-lock source/filesystem preparation must precede our explicit transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        $readiness = app(ProofScanReadiness::class);
        $readiness->prepareMigration('M10');
        $ddl = $this->definitions();

        DB::transaction(function () use ($readiness, $ddl): void {
            $this->timeouts();
            $readiness->assertMigrationTarget('M10');
            DB::unprepared($ddl);
            $readiness->assertInstalled(false);
        }, 1);
    }

    public function down(): void
    {
        $readiness = app(ProofScanReadiness::class);
        $readiness->prepareMigration('DOWN10');
        $statements = $readiness->removalStatements(false);

        DB::transaction(function () use ($readiness, $statements): void {
            $this->timeouts();
            $readiness->assertMigrationTarget('DOWN10');
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
        CREATE FUNCTION public.proof_scan_fail(p_rule text) RETURNS void
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RAISE EXCEPTION USING ERRCODE = '23514',
                MESSAGE = 'Technical proof invariant: constraint "' || p_rule || '"', CONSTRAINT = p_rule;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_digest(p_domain text, p_fields jsonb) RETURNS text
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN encode(sha256(convert_to(p_domain || chr(10) || p_fields::text, 'UTF8')), 'hex');
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_active(p_state character varying) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN RETURN COALESCE(p_state IN ('LEASED','RUNNING'), false); END;
        $fn$;

        CREATE FUNCTION public.proof_scan_ready(p_state character varying) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN RETURN COALESCE(p_state IN ('READY','RETRY_WAIT'), false); END;
        $fn$;

        CREATE FUNCTION public.proof_scan_mutation(p_event character varying) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN RETURN COALESCE(p_event IN ('initial_subject_bound','subject_transition'), false); END;
        $fn$;

        CREATE FUNCTION public.proof_scan_policy_shape(p_stage jsonb, p_versions jsonb, p_retry jsonb, p_freshness jsonb, p_resource jsonb) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE(
                p_stage = '[{"stage_id":"synthetic_stage_v1","is_simulation":true}]'::jsonb
                AND p_versions = '{"adapter_version":"1","scanner_identity":"synthetic_mock","engine_id":"synthetic_mock","engine_version":"1","signature_set_id":"not_applicable_simulation","signature_version":"not_applicable_simulation","auth_scheme_version":"trusted_in_process_v1","key_epoch_reference":"not_applicable_owned_runtime"}'::jsonb
                AND p_retry = '{"max_attempts":2,"adapter_timeout_seconds":5,"lease_seconds":30,"retry_due_seconds":1}'::jsonb
                AND p_freshness = '{"verdict_seconds":120}'::jsonb
                AND p_resource = '{"worker_concurrency":1,"dispatcher":"manual_one_shot"}'::jsonb, false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psp_version(p_version varchar(64), p_definition_version smallint) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_version = 'academic-sim-v1' AND p_definition_version = 1), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psp_digest(p_definition_digest char(64)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_definition_digest ~ '^[0-9a-f]{64}$'), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psp_profile(p_stage_plan jsonb, p_allowed_versions jsonb, p_retry_profile jsonb, p_freshness_profile jsonb, p_resource_profile jsonb, p_approval_reference varchar(256), p_created_by_function varchar(128)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((public.proof_scan_policy_shape(p_stage_plan,p_allowed_versions,p_retry_profile,p_freshness_profile,p_resource_profile) AND p_approval_reference ~ '^academic-owned:[0-9a-f]{64}$' AND p_created_by_function = 'proof_scan_create_policy'), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psh_scope(p_scope_key varchar(64), p_admission varchar(16), p_authority_ref varchar(256)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_scope_key = 'academic_owned_run' AND p_admission IN ('held','active') AND p_authority_ref ~ '^academic-owned:[0-9a-f]{64}$'), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psh_epoch(p_epoch bigint) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_epoch > 0), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_pss_tuple(p_proof_disk varchar(32), p_proof_path varchar(512), p_proof_reference uuid, p_proof_sha256 char(64), p_subject_digest char(64), p_proof_size_bytes bigint, p_proof_mime varchar(32), p_descriptor_version smallint) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_proof_disk = 'local' AND p_proof_sha256 ~ '^[0-9a-f]{64}$' AND p_subject_digest ~ '^[0-9a-f]{64}$' AND p_proof_size_bytes BETWEEN 1 AND 2097152 AND p_descriptor_version = 1 AND ((p_proof_mime = 'image/jpeg' AND p_proof_path = 'payment-proofs/' || p_proof_reference::text || '/object.jpg') OR (p_proof_mime = 'image/png' AND p_proof_path = 'payment-proofs/' || p_proof_reference::text || '/object.png'))), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_pss_versions(p_generation bigint, p_state_version bigint, p_policy_epoch bigint, p_changed_at timestamptz(6), p_created_at timestamptz(6)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_generation > 0 AND p_state_version > 0 AND p_policy_epoch > 0 AND isfinite(p_created_at) AND isfinite(p_changed_at) AND p_changed_at >= p_created_at), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_pss_state(p_state varchar(24)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_state IN ('HELD','QUEUED','SCANNING','CLEAN_ELIGIBLE','INFECTED_HELD','UNKNOWN_HELD','ERROR_HELD','STALE_HELD','RETIRED')), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_pss_hold(p_state varchar(24), p_held_reason varchar(48), p_security_hold boolean, p_hold_authority_ref varchar(256), p_hold_reason varchar(48)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_held_reason = lower(p_state) AND ((p_security_hold AND p_hold_authority_ref ~ '^academic-owned:[0-9a-f]{64}$' AND p_hold_reason IN ('infected','authority_hold') AND p_state = CASE WHEN p_hold_reason = 'infected' THEN 'INFECTED_HELD' ELSE 'HELD' END) OR (NOT p_security_hold AND p_hold_authority_ref IS NULL AND p_hold_reason IS NULL AND p_state NOT IN ('INFECTED_HELD','HELD')))), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psj_state(p_state varchar(24), p_attempt_count integer, p_current_attempt_id uuid, p_terminal_reason varchar(48)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((((p_state = 'READY' AND p_attempt_count = 0 AND p_current_attempt_id IS NULL AND p_terminal_reason IS NULL) OR (p_state IN ('LEASED','RETRY_WAIT') AND p_attempt_count > 0 AND p_current_attempt_id IS NOT NULL AND p_terminal_reason IS NULL) OR (p_state IN ('COMPLETED','DEAD_LETTER') AND p_attempt_count > 0 AND p_current_attempt_id IS NOT NULL AND p_terminal_reason IN ('clean','infected','unknown','error','lease_expired','adapter_failed')) OR (p_state = 'SUPERSEDED' AND p_terminal_reason = 'rescan'))), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psj_counts(p_attempt_count integer, p_generation bigint, p_policy_epoch bigint, p_fence bigint) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_attempt_count BETWEEN 0 AND 2 AND p_generation > 0 AND p_policy_epoch > 0 AND p_fence = greatest(1,p_attempt_count)), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psj_stage(p_stage_id varchar(48), p_stage_plan_digest char(64), p_origin varchar(24), p_requested_by_function varchar(128), p_authority_ref varchar(256)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_stage_id = 'synthetic_stage_v1' AND p_stage_plan_digest ~ '^[0-9a-f]{64}$' AND ((p_origin = 'fresh_submit' AND p_requested_by_function = 'proof_scan_initialize_subject') OR (p_origin = 'authorized_rescan' AND p_requested_by_function = 'proof_scan_rescan_or_dispose')) AND p_authority_ref ~ '^academic-owned:[0-9a-f]{64}$'), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psj_times(p_created_at timestamptz(6), p_changed_at timestamptz(6), p_not_before timestamptz(6)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((isfinite(p_created_at) AND isfinite(p_changed_at) AND isfinite(p_not_before) AND p_changed_at >= p_created_at), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psa_state(p_state varchar(24)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_state IN ('LEASED','RUNNING','ACCEPTED','FAILED','EXPIRED','SUPERSEDED')), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psa_ordinal(p_attempt_no integer, p_generation bigint, p_fence bigint, p_identity_epoch bigint) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_attempt_no BETWEEN 1 AND 2 AND p_generation > 0 AND p_fence = p_attempt_no AND p_identity_epoch > 0), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psa_times(p_leased_at timestamptz(6), p_lease_deadline timestamptz(6), p_started_at timestamptz(6), p_completed_at timestamptz(6)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((isfinite(p_leased_at) AND isfinite(p_lease_deadline) AND p_lease_deadline = p_leased_at + interval '30 seconds' AND (p_started_at IS NULL OR (isfinite(p_started_at) AND p_started_at >= p_leased_at AND p_started_at < p_lease_deadline)) AND (p_completed_at IS NULL OR (isfinite(p_completed_at) AND p_completed_at >= p_leased_at))), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psa_shape(p_state varchar(24), p_started_at timestamptz(6), p_completed_at timestamptz(6), p_failure_code varchar(48), p_scanner_identity varchar(128), p_deployment_audience varchar(256)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_scanner_identity = 'synthetic_mock' AND p_deployment_audience ~ '^academic-owned:[0-9a-f]{64}$' AND ((p_state = 'LEASED' AND p_started_at IS NULL AND p_completed_at IS NULL AND p_failure_code IS NULL) OR (p_state = 'RUNNING' AND p_started_at IS NOT NULL AND p_completed_at IS NULL AND p_failure_code IS NULL) OR (p_state = 'ACCEPTED' AND p_completed_at IS NOT NULL AND p_failure_code IS NULL) OR (p_state IN ('FAILED','EXPIRED','SUPERSEDED') AND p_completed_at IS NOT NULL AND p_failure_code IN ('adapter_failed','lease_expired','rescan')))), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psv_shape(p_verdict varchar(16), p_reason_code varchar(48), p_subject_digest char(64), p_observed_sha256 char(64), p_envelope_digest char(64), p_observed_size_bytes bigint, p_observed_mime varchar(32), p_generation bigint, p_fence bigint, p_policy_epoch bigint, p_identity_epoch bigint, p_stage_id varchar(48), p_observed_at timestamptz(6), p_received_at timestamptz(6)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_verdict IN ('clean','infected','unknown','error') AND p_reason_code = 'simulation_' || p_verdict AND p_subject_digest ~ '^[0-9a-f]{64}$' AND p_observed_sha256 ~ '^[0-9a-f]{64}$' AND p_envelope_digest ~ '^[0-9a-f]{64}$' AND p_observed_size_bytes BETWEEN 1 AND 2097152 AND p_observed_mime IN ('image/jpeg','image/png') AND p_generation > 0 AND p_fence BETWEEN 1 AND 2 AND p_policy_epoch > 0 AND p_identity_epoch > 0 AND p_stage_id = 'synthetic_stage_v1' AND isfinite(p_observed_at) AND isfinite(p_received_at) AND p_observed_at <= p_received_at), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_psv_simulation(p_scanner_identity varchar(128), p_engine_id varchar(128), p_engine_version varchar(128), p_signature_set_id varchar(128), p_signature_version varchar(128), p_auth_scheme_version varchar(64), p_key_epoch_reference varchar(128)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_scanner_identity = 'synthetic_mock' AND p_engine_id = 'synthetic_mock' AND p_engine_version = '1' AND p_signature_set_id = 'not_applicable_simulation' AND p_signature_version = 'not_applicable_simulation' AND p_auth_scheme_version = 'trusted_in_process_v1' AND p_key_epoch_reference = 'not_applicable_owned_runtime'), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_pse_digest(p_event_digest char(64)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_event_digest ~ '^[0-9a-f]{64}$'), false);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_pse_event_shape(p_event_type varchar(48), p_reason_code varchar(48), p_principal_kind varchar(32), p_principal_ref varchar(128), p_subject_id uuid, p_job_id uuid, p_attempt_id uuid, p_verdict_id uuid, p_policy_id uuid, p_admission_incarnation uuid, p_generation bigint, p_fence bigint, p_policy_epoch bigint, p_authority_ref varchar(256), p_descriptor_digest char(64), p_audience_ref varchar(128), p_authorization_binding_digest char(64), p_subject_state_version bigint, p_subject_state varchar(24), p_recorded_at timestamptz(6)) RETURNS boolean
        LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN COALESCE((p_event_type IN ('policy_created','policy_activated','policy_held','initial_subject_bound','job_created','attempt_claimed','attempt_started','attempt_expired','attempt_failed','result_accepted','subject_transition','protected_get_granted','verify_use_granted','rescan_requested','job_superseded','security_hold_disposed') AND p_reason_code IN ('policy_created','policy_activated','policy_held','initial_subject_bound','job_created','attempt_claimed','attempt_started','attempt_expired','attempt_failed','result_accepted','subject_transition','protected_get_granted','verify_use_granted','rescan_requested','job_superseded','security_hold_disposed') AND p_reason_code = p_event_type AND isfinite(p_recorded_at) AND p_authority_ref ~ '^academic-owned:[0-9a-f]{64}$' AND p_policy_id IS NOT NULL AND ((p_subject_id IS NULL AND p_event_type IN ('policy_created','policy_activated','policy_held') AND p_job_id IS NULL AND p_attempt_id IS NULL AND p_verdict_id IS NULL AND p_generation IS NULL AND p_fence IS NULL AND p_descriptor_digest IS NULL AND p_subject_state_version IS NULL AND p_subject_state IS NULL AND p_audience_ref IS NULL AND p_authorization_binding_digest IS NULL AND p_principal_kind = 'technical_function' AND p_principal_ref = p_event_type
                AND ((p_event_type='policy_created' AND p_policy_epoch IS NULL AND p_admission_incarnation IS NULL)
                  OR (p_event_type IN ('policy_activated','policy_held') AND p_policy_epoch>0 AND p_admission_incarnation IS NOT NULL))) OR (p_subject_id IS NOT NULL AND p_event_type NOT IN ('policy_created','policy_activated','policy_held') AND p_generation > 0 AND p_policy_epoch > 0 AND p_admission_incarnation IS NOT NULL AND p_subject_state_version > 0 AND p_subject_state IN ('HELD','QUEUED','SCANNING','CLEAN_ELIGIBLE','INFECTED_HELD','UNKNOWN_HELD','ERROR_HELD','STALE_HELD','RETIRED') AND p_descriptor_digest ~ '^[0-9a-f]{64}$' AND ((p_event_type IN ('protected_get_granted','verify_use_granted') AND p_subject_state = 'CLEAN_ELIGIBLE' AND p_job_id IS NOT NULL AND p_attempt_id IS NOT NULL AND p_verdict_id IS NOT NULL AND p_fence > 0 AND p_principal_kind = 'user' AND p_principal_ref ~ '^[0-9a-f-]{36}$' AND p_audience_ref ~ '^[0-9a-f]{64}$' AND p_authorization_binding_digest ~ '^[0-9a-f]{64}$') OR (p_event_type NOT IN ('protected_get_granted','verify_use_granted') AND p_principal_kind = 'technical_function' AND p_principal_ref = p_event_type AND p_audience_ref IS NULL AND p_authorization_binding_digest IS NULL))))), false);
        END;
        $fn$;

        CREATE TABLE public.proof_scan_policies (
            id uuid CONSTRAINT psp_id_nn NOT NULL,
            version varchar(64) CONSTRAINT psp_version_nn NOT NULL,
            definition_version smallint CONSTRAINT psp_definition_version_nn NOT NULL,
            definition_digest char(64) CONSTRAINT psp_definition_digest_nn NOT NULL,
            stage_plan jsonb CONSTRAINT psp_stage_plan_nn NOT NULL,
            allowed_versions jsonb CONSTRAINT psp_allowed_versions_nn NOT NULL,
            retry_profile jsonb CONSTRAINT psp_retry_profile_nn NOT NULL,
            freshness_profile jsonb CONSTRAINT psp_freshness_profile_nn NOT NULL,
            resource_profile jsonb CONSTRAINT psp_resource_profile_nn NOT NULL,
            approval_reference varchar(256) CONSTRAINT psp_approval_reference_nn NOT NULL,
            created_by_function varchar(128) CONSTRAINT psp_created_by_function_nn NOT NULL,
            created_at timestamptz(6) CONSTRAINT psp_created_at_nn NOT NULL,
            CONSTRAINT psp_version_ck CHECK (public.proof_scan_psp_version(version, definition_version)),
            CONSTRAINT psp_digest_ck CHECK (public.proof_scan_psp_digest(definition_digest)),
            CONSTRAINT psp_profile_ck CHECK (public.proof_scan_psp_profile(stage_plan, allowed_versions, retry_profile, freshness_profile, resource_profile, approval_reference, created_by_function)),
            CONSTRAINT psp_pkey PRIMARY KEY (id),
            CONSTRAINT psp_version_uq UNIQUE (version),
            CONSTRAINT psp_digest_uq UNIQUE (definition_digest)
        );

        CREATE TABLE public.proof_scan_policy_heads (
            id uuid CONSTRAINT psh_id_nn NOT NULL,
            scope_key varchar(64) CONSTRAINT psh_scope_key_nn NOT NULL,
            policy_id uuid CONSTRAINT psh_policy_id_nn NOT NULL,
            admission_incarnation uuid CONSTRAINT psh_admission_incarnation_nn NOT NULL,
            epoch bigint CONSTRAINT psh_epoch_nn NOT NULL,
            admission varchar(16) CONSTRAINT psh_admission_nn NOT NULL,
            authority_ref varchar(256) CONSTRAINT psh_authority_ref_nn NOT NULL,
            changed_at timestamptz(6) CONSTRAINT psh_changed_at_nn NOT NULL,
            CONSTRAINT psh_scope_ck CHECK (public.proof_scan_psh_scope(scope_key, admission, authority_ref)),
            CONSTRAINT psh_epoch_ck CHECK (public.proof_scan_psh_epoch(epoch)),
            CONSTRAINT psh_pkey PRIMARY KEY (id),
            CONSTRAINT psh_scope_uq UNIQUE (scope_key),
            CONSTRAINT psh_policy_fk FOREIGN KEY (policy_id) REFERENCES public.proof_scan_policies (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        );

        CREATE TABLE public.proof_scan_subjects (
            id uuid CONSTRAINT pss_id_nn NOT NULL,
            payment_id uuid CONSTRAINT pss_payment_id_nn NOT NULL,
            invoice_id uuid CONSTRAINT pss_invoice_id_nn NOT NULL,
            revision_id uuid CONSTRAINT pss_revision_id_nn NOT NULL,
            submitter_id uuid CONSTRAINT pss_submitter_id_nn NOT NULL,
            proof_reference uuid CONSTRAINT pss_proof_reference_nn NOT NULL,
            billing_request_id uuid CONSTRAINT pss_billing_request_id_nn NOT NULL,
            billing_action_id uuid CONSTRAINT pss_billing_action_id_nn NOT NULL,
            proof_disk varchar(32) CONSTRAINT pss_proof_disk_nn NOT NULL,
            proof_path varchar(512) CONSTRAINT pss_proof_path_nn NOT NULL,
            proof_sha256 char(64) CONSTRAINT pss_proof_sha256_nn NOT NULL,
            subject_digest char(64) CONSTRAINT pss_subject_digest_nn NOT NULL,
            proof_size_bytes bigint CONSTRAINT pss_proof_size_bytes_nn NOT NULL,
            proof_mime varchar(32) CONSTRAINT pss_proof_mime_nn NOT NULL,
            descriptor_version smallint CONSTRAINT pss_descriptor_version_nn NOT NULL,
            created_at timestamptz(6) CONSTRAINT pss_created_at_nn NOT NULL,
            changed_at timestamptz(6) CONSTRAINT pss_changed_at_nn NOT NULL,
            generation bigint CONSTRAINT pss_generation_nn NOT NULL,
            state_version bigint CONSTRAINT pss_state_version_nn NOT NULL,
            policy_epoch bigint CONSTRAINT pss_policy_epoch_nn NOT NULL,
            policy_id uuid CONSTRAINT pss_policy_id_nn NOT NULL,
            admission_incarnation uuid CONSTRAINT pss_admission_incarnation_nn NOT NULL,
            state varchar(24) CONSTRAINT pss_state_nn NOT NULL,
            held_reason varchar(48) CONSTRAINT pss_held_reason_nn NOT NULL,
            security_hold boolean CONSTRAINT pss_security_hold_nn NOT NULL,
            hold_authority_ref varchar(256),
            hold_reason varchar(48),
            CONSTRAINT pss_tuple_ck CHECK (public.proof_scan_pss_tuple(proof_disk, proof_path, proof_reference, proof_sha256, subject_digest, proof_size_bytes, proof_mime, descriptor_version)),
            CONSTRAINT pss_versions_ck CHECK (public.proof_scan_pss_versions(generation, state_version, policy_epoch, changed_at, created_at)),
            CONSTRAINT pss_state_ck CHECK (public.proof_scan_pss_state(state)),
            CONSTRAINT pss_hold_ck CHECK (public.proof_scan_pss_hold(state, held_reason, security_hold, hold_authority_ref, hold_reason)),
            CONSTRAINT pss_pkey PRIMARY KEY (id),
            CONSTRAINT pss_payment_uq UNIQUE (payment_id),
            CONSTRAINT pss_ref_uq UNIQUE (proof_reference),
            CONSTRAINT pss_payment_fk FOREIGN KEY (invoice_id, payment_id) REFERENCES public.payments (invoice_id, id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pss_revision_fk FOREIGN KEY (invoice_id, revision_id) REFERENCES public.invoice_revisions (invoice_id, id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pss_submitter_fk FOREIGN KEY (submitter_id) REFERENCES public.users (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pss_request_fk FOREIGN KEY (billing_request_id, invoice_id, submitter_id) REFERENCES public.billing_requests (id, invoice_id, actor_id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pss_action_fk FOREIGN KEY (billing_action_id) REFERENCES public.billing_actions (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pss_policy_fk FOREIGN KEY (policy_id) REFERENCES public.proof_scan_policies (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        );

        CREATE TABLE public.proof_scan_jobs (
            id uuid CONSTRAINT psj_id_nn NOT NULL,
            subject_id uuid CONSTRAINT psj_subject_id_nn NOT NULL,
            policy_id uuid CONSTRAINT psj_policy_id_nn NOT NULL,
            admission_incarnation uuid CONSTRAINT psj_admission_incarnation_nn NOT NULL,
            generation bigint CONSTRAINT psj_generation_nn NOT NULL,
            policy_epoch bigint CONSTRAINT psj_policy_epoch_nn NOT NULL,
            fence bigint CONSTRAINT psj_fence_nn NOT NULL,
            stage_id varchar(48) CONSTRAINT psj_stage_id_nn NOT NULL,
            stage_plan_digest char(64) CONSTRAINT psj_stage_plan_digest_nn NOT NULL,
            state varchar(24) CONSTRAINT psj_state_nn NOT NULL,
            not_before timestamptz(6) CONSTRAINT psj_not_before_nn NOT NULL,
            created_at timestamptz(6) CONSTRAINT psj_created_at_nn NOT NULL,
            changed_at timestamptz(6) CONSTRAINT psj_changed_at_nn NOT NULL,
            attempt_count integer CONSTRAINT psj_attempt_count_nn NOT NULL,
            current_attempt_id uuid,
            terminal_reason varchar(48),
            origin varchar(24) CONSTRAINT psj_origin_nn NOT NULL,
            requested_by_function varchar(128) CONSTRAINT psj_requested_by_function_nn NOT NULL,
            authority_ref varchar(256) CONSTRAINT psj_authority_ref_nn NOT NULL,
            CONSTRAINT psj_state_ck CHECK (public.proof_scan_psj_state(state, attempt_count, current_attempt_id, terminal_reason)),
            CONSTRAINT psj_counts_ck CHECK (public.proof_scan_psj_counts(attempt_count, generation, policy_epoch, fence)),
            CONSTRAINT psj_stage_ck CHECK (public.proof_scan_psj_stage(stage_id, stage_plan_digest, origin, requested_by_function, authority_ref)),
            CONSTRAINT psj_times_ck CHECK (public.proof_scan_psj_times(created_at, changed_at, not_before)),
            CONSTRAINT psj_pkey PRIMARY KEY (id),
            CONSTRAINT psj_stage_uq UNIQUE (subject_id, generation, stage_id),
            CONSTRAINT psj_bind_uq UNIQUE (id, subject_id, policy_id, generation, policy_epoch, stage_id, admission_incarnation),
            CONSTRAINT psj_subject_fk FOREIGN KEY (subject_id) REFERENCES public.proof_scan_subjects (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT psj_policy_fk FOREIGN KEY (policy_id) REFERENCES public.proof_scan_policies (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        );

        CREATE TABLE public.proof_scan_attempts (
            id uuid CONSTRAINT psa_id_nn NOT NULL,
            job_id uuid CONSTRAINT psa_job_id_nn NOT NULL,
            challenge_id uuid CONSTRAINT psa_challenge_id_nn NOT NULL,
            admission_incarnation uuid CONSTRAINT psa_admission_incarnation_nn NOT NULL,
            attempt_no integer CONSTRAINT psa_attempt_no_nn NOT NULL,
            generation bigint CONSTRAINT psa_generation_nn NOT NULL,
            fence bigint CONSTRAINT psa_fence_nn NOT NULL,
            identity_epoch bigint CONSTRAINT psa_identity_epoch_nn NOT NULL,
            scanner_identity varchar(128) CONSTRAINT psa_scanner_identity_nn NOT NULL,
            deployment_audience varchar(256) CONSTRAINT psa_deployment_audience_nn NOT NULL,
            state varchar(24) CONSTRAINT psa_state_nn NOT NULL,
            leased_at timestamptz(6) CONSTRAINT psa_leased_at_nn NOT NULL,
            lease_deadline timestamptz(6) CONSTRAINT psa_lease_deadline_nn NOT NULL,
            started_at timestamptz(6),
            completed_at timestamptz(6),
            failure_code varchar(48),
            CONSTRAINT psa_state_ck CHECK (public.proof_scan_psa_state(state)),
            CONSTRAINT psa_ordinal_ck CHECK (public.proof_scan_psa_ordinal(attempt_no, generation, fence, identity_epoch)),
            CONSTRAINT psa_times_ck CHECK (public.proof_scan_psa_times(leased_at, lease_deadline, started_at, completed_at)),
            CONSTRAINT psa_shape_ck CHECK (public.proof_scan_psa_shape(state, started_at, completed_at, failure_code, scanner_identity, deployment_audience)),
            CONSTRAINT psa_pkey PRIMARY KEY (id),
            CONSTRAINT psa_challenge_uq UNIQUE (challenge_id),
            CONSTRAINT psa_ordinal_uq UNIQUE (job_id, attempt_no),
            CONSTRAINT psa_fence_uq UNIQUE (job_id, fence),
            CONSTRAINT psa_job_id_uq UNIQUE (job_id, id),
            CONSTRAINT psa_bind_uq UNIQUE (id, job_id, generation, fence, admission_incarnation),
            CONSTRAINT psa_job_fk FOREIGN KEY (job_id) REFERENCES public.proof_scan_jobs (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        );

        ALTER TABLE public.proof_scan_jobs ADD CONSTRAINT psj_current_attempt_fk FOREIGN KEY (id, current_attempt_id) REFERENCES public.proof_scan_attempts (job_id, id) ON UPDATE NO ACTION ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED;

        CREATE TABLE public.proof_scan_verdicts (
            id uuid CONSTRAINT psv_id_nn NOT NULL,
            result_id uuid CONSTRAINT psv_result_id_nn NOT NULL,
            attempt_id uuid CONSTRAINT psv_attempt_id_nn NOT NULL,
            job_id uuid CONSTRAINT psv_job_id_nn NOT NULL,
            subject_id uuid CONSTRAINT psv_subject_id_nn NOT NULL,
            policy_id uuid CONSTRAINT psv_policy_id_nn NOT NULL,
            admission_incarnation uuid CONSTRAINT psv_admission_incarnation_nn NOT NULL,
            generation bigint CONSTRAINT psv_generation_nn NOT NULL,
            fence bigint CONSTRAINT psv_fence_nn NOT NULL,
            policy_epoch bigint CONSTRAINT psv_policy_epoch_nn NOT NULL,
            identity_epoch bigint CONSTRAINT psv_identity_epoch_nn NOT NULL,
            observed_size_bytes bigint CONSTRAINT psv_observed_size_bytes_nn NOT NULL,
            subject_digest char(64) CONSTRAINT psv_subject_digest_nn NOT NULL,
            observed_sha256 char(64) CONSTRAINT psv_observed_sha256_nn NOT NULL,
            envelope_digest char(64) CONSTRAINT psv_envelope_digest_nn NOT NULL,
            stage_id varchar(48) CONSTRAINT psv_stage_id_nn NOT NULL,
            reason_code varchar(48) CONSTRAINT psv_reason_code_nn NOT NULL,
            observed_mime varchar(32) CONSTRAINT psv_observed_mime_nn NOT NULL,
            verdict varchar(16) CONSTRAINT psv_verdict_nn NOT NULL,
            scanner_identity varchar(128) CONSTRAINT psv_scanner_identity_nn NOT NULL,
            engine_id varchar(128) CONSTRAINT psv_engine_id_nn NOT NULL,
            engine_version varchar(128) CONSTRAINT psv_engine_version_nn NOT NULL,
            signature_set_id varchar(128) CONSTRAINT psv_signature_set_id_nn NOT NULL,
            signature_version varchar(128) CONSTRAINT psv_signature_version_nn NOT NULL,
            observed_at timestamptz(6) CONSTRAINT psv_observed_at_nn NOT NULL,
            received_at timestamptz(6) CONSTRAINT psv_received_at_nn NOT NULL,
            auth_scheme_version varchar(64) CONSTRAINT psv_auth_scheme_version_nn NOT NULL,
            key_epoch_reference varchar(128) CONSTRAINT psv_key_epoch_reference_nn NOT NULL,
            CONSTRAINT psv_shape_ck CHECK (public.proof_scan_psv_shape(verdict, reason_code, subject_digest, observed_sha256, envelope_digest, observed_size_bytes, observed_mime, generation, fence, policy_epoch, identity_epoch, stage_id, observed_at, received_at)),
            CONSTRAINT psv_simulation_ck CHECK (public.proof_scan_psv_simulation(scanner_identity, engine_id, engine_version, signature_set_id, signature_version, auth_scheme_version, key_epoch_reference)),
            CONSTRAINT psv_pkey PRIMARY KEY (id),
            CONSTRAINT psv_attempt_uq UNIQUE (attempt_id),
            CONSTRAINT psv_identity_result_uq UNIQUE (scanner_identity, result_id),
            CONSTRAINT psv_subject_fk FOREIGN KEY (subject_id) REFERENCES public.proof_scan_subjects (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT psv_job_bind_fk FOREIGN KEY (job_id, subject_id, policy_id, generation, policy_epoch, stage_id, admission_incarnation) REFERENCES public.proof_scan_jobs (id, subject_id, policy_id, generation, policy_epoch, stage_id, admission_incarnation) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT psv_attempt_bind_fk FOREIGN KEY (attempt_id, job_id, generation, fence, admission_incarnation) REFERENCES public.proof_scan_attempts (id, job_id, generation, fence, admission_incarnation) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT psv_policy_fk FOREIGN KEY (policy_id) REFERENCES public.proof_scan_policies (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        );

        CREATE TABLE public.proof_scan_audit_events (
            id uuid CONSTRAINT pse_id_nn NOT NULL,
            event_key uuid CONSTRAINT pse_event_key_nn NOT NULL,
            correlation_id uuid CONSTRAINT pse_correlation_id_nn NOT NULL,
            recorded_at timestamptz(6) CONSTRAINT pse_recorded_at_nn NOT NULL,
            event_type varchar(48) CONSTRAINT pse_event_type_nn NOT NULL,
            reason_code varchar(48) CONSTRAINT pse_reason_code_nn NOT NULL,
            principal_kind varchar(32) CONSTRAINT pse_principal_kind_nn NOT NULL,
            principal_ref varchar(128) CONSTRAINT pse_principal_ref_nn NOT NULL,
            subject_id uuid,
            job_id uuid,
            attempt_id uuid,
            verdict_id uuid,
            policy_id uuid,
            admission_incarnation uuid,
            generation bigint,
            fence bigint,
            policy_epoch bigint,
            authority_ref varchar(256),
            descriptor_digest char(64),
            event_digest char(64) CONSTRAINT pse_event_digest_nn NOT NULL,
            audience_ref varchar(128),
            authorization_binding_digest char(64),
            subject_state_version bigint,
            subject_state varchar(24),
            CONSTRAINT pse_digest_ck CHECK (public.proof_scan_pse_digest(event_digest)),
            CONSTRAINT pse_event_shape_ck CHECK (public.proof_scan_pse_event_shape(event_type, reason_code, principal_kind, principal_ref, subject_id, job_id, attempt_id, verdict_id, policy_id, admission_incarnation, generation, fence, policy_epoch, authority_ref, descriptor_digest, audience_ref, authorization_binding_digest, subject_state_version, subject_state, recorded_at)),
            CONSTRAINT pse_pkey PRIMARY KEY (id),
            CONSTRAINT pse_event_key_uq UNIQUE (event_key),
            CONSTRAINT pse_subject_fk FOREIGN KEY (subject_id) REFERENCES public.proof_scan_subjects (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pse_job_fk FOREIGN KEY (job_id) REFERENCES public.proof_scan_jobs (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pse_attempt_fk FOREIGN KEY (attempt_id) REFERENCES public.proof_scan_attempts (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pse_verdict_fk FOREIGN KEY (verdict_id) REFERENCES public.proof_scan_verdicts (id) ON UPDATE NO ACTION ON DELETE NO ACTION,
            CONSTRAINT pse_policy_fk FOREIGN KEY (policy_id) REFERENCES public.proof_scan_policies (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        );

        CREATE INDEX pss_state_changed_idx ON public.proof_scan_subjects (state, changed_at, id);

        CREATE INDEX pss_policy_epoch_idx ON public.proof_scan_subjects (policy_id, policy_epoch, id);

        CREATE INDEX psp_created_idx ON public.proof_scan_policies (created_at, id);

        CREATE INDEX psj_ready_idx ON public.proof_scan_jobs (not_before, id) WHERE public.proof_scan_ready(state);

        CREATE INDEX psj_subject_gen_idx ON public.proof_scan_jobs (subject_id, generation, id);

        CREATE UNIQUE INDEX psa_active_job_uq ON public.proof_scan_attempts (job_id) WHERE public.proof_scan_active(state);

        CREATE UNIQUE INDEX psa_runtime_active_uq ON public.proof_scan_attempts (admission_incarnation) WHERE public.proof_scan_active(state);

        CREATE INDEX psa_expiry_idx ON public.proof_scan_attempts (lease_deadline, id) WHERE public.proof_scan_active(state);

        CREATE INDEX psv_subject_stage_idx ON public.proof_scan_verdicts (subject_id, generation, stage_id);

        CREATE INDEX psv_received_idx ON public.proof_scan_verdicts (received_at, id);

        CREATE INDEX pse_subject_time_idx ON public.proof_scan_audit_events (subject_id, recorded_at, id);

        CREATE INDEX pse_type_time_idx ON public.proof_scan_audit_events (event_type, recorded_at, id);

        CREATE UNIQUE INDEX pse_subject_version_uq ON public.proof_scan_audit_events (subject_id, subject_state_version) WHERE public.proof_scan_mutation(event_type);

        CREATE FUNCTION public.proof_scan_authority() RETURNS text
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE d record;
        BEGIN
            SELECT oid, datname, datdba, shobj_description(oid,'pg_database') AS marker
              INTO d FROM pg_database WHERE datname = current_database();
            IF d.datname !~ '^silansia_test_[0-9a-f]{16}$'
               OR pg_get_userbyid(d.datdba) IS DISTINCT FROM current_user
               OR d.marker IS NULL OR d.marker !~ '^silansia-phase1:[0-9a-f]{64}$'
               OR current_setting('TimeZone') <> 'UTC'
               OR current_setting('session_replication_role') <> 'origin'
               OR current_setting('transaction_isolation') <> 'read committed'
               OR current_setting('transaction_read_only') <> 'off' THEN
                PERFORM public.proof_scan_fail('pscan_owned_runtime');
            END IF;
            -- Opaque run binding only; never copy the runner ownership token to assignments/audit.
            RETURN 'academic-owned:' || encode(sha256(convert_to(
                'silansia.proof.authority.academic.v1' || chr(10) || d.oid::text || chr(10) || d.marker, 'UTF8')), 'hex');
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_environment(p_authority text) RETURNS void
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            IF p_authority IS DISTINCT FROM public.proof_scan_authority() THEN
                PERFORM public.proof_scan_fail('pscan_authority');
            END IF;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_subject_digest(p_subject jsonb) RETURNS text
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN
            RETURN public.proof_scan_digest('silansia.proof.subject.academic.v1', jsonb_build_array(
                p_subject->'payment_id',p_subject->'invoice_id',p_subject->'revision_id',
                p_subject->'submitter_id',p_subject->'proof_reference',p_subject->'billing_request_id',
                p_subject->'billing_action_id',p_subject->'proof_disk',p_subject->'proof_path',
                p_subject->'proof_sha256',p_subject->'proof_size_bytes',p_subject->'proof_mime',
                p_subject->'descriptor_version'));
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_record_event(p_event text, p_subject uuid, p_job uuid, p_attempt uuid, p_verdict uuid, p_policy uuid, p_epoch bigint, p_incarnation uuid, p_at timestamp with time zone, p_authority text, p_principal uuid, p_audience text, p_binding text) RETURNS uuid
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE q public.proof_scan_subjects%ROWTYPE; j public.proof_scan_jobs%ROWTYPE;
            e public.proof_scan_audit_events%ROWTYPE;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            IF p_subject IS NOT NULL THEN
                SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=p_subject;
            END IF;
            IF p_job IS NOT NULL THEN
                SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE id=p_job;
                IF j.subject_id IS DISTINCT FROM q.id THEN PERFORM public.proof_scan_fail('pscan_audit_scope'); END IF;
            END IF;
            e.id := gen_random_uuid(); e.event_key := gen_random_uuid(); e.correlation_id := COALESCE(q.id,p_policy,e.event_key);
            e.recorded_at := p_at; e.event_type := p_event; e.reason_code := p_event;
            e.principal_kind := CASE WHEN p_principal IS NULL THEN 'technical_function' ELSE 'user' END;
            e.principal_ref := COALESCE(p_principal::text,p_event);
            e.subject_id := q.id; e.job_id := j.id; e.attempt_id := p_attempt; e.verdict_id := p_verdict;
            e.policy_id := COALESCE(q.policy_id,p_policy); e.admission_incarnation := COALESCE(q.admission_incarnation,p_incarnation);
            e.generation := q.generation; e.fence := j.fence; e.policy_epoch := COALESCE(q.policy_epoch,p_epoch);
            e.authority_ref := p_authority; e.descriptor_digest := q.subject_digest;
            e.audience_ref := p_audience; e.authorization_binding_digest := p_binding;
            e.subject_state_version := q.state_version; e.subject_state := q.state;
            e.event_digest := public.proof_scan_digest('silansia.proof.audit.academic.v1',to_jsonb(e)-'event_digest');
            INSERT INTO public.proof_scan_audit_events SELECT e.*;
            RETURN e.id;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_create_policy(p_definition jsonb, p_authority text) RETURNS uuid
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE p public.proof_scan_policies%ROWTYPE;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            IF jsonb_typeof(p_definition) IS DISTINCT FROM 'object'
               OR (SELECT array_agg(k ORDER BY k) FROM jsonb_object_keys(p_definition) k)
                  IS DISTINCT FROM ARRAY['allowed_versions','definition_version','freshness_profile','resource_profile','retry_profile','stage_plan','version']::text[] THEN
                PERFORM public.proof_scan_fail('pscan_policy_definition');
            END IF;
            p.id := gen_random_uuid(); p.version := p_definition->>'version';
            p.definition_version := (p_definition->>'definition_version')::smallint;
            p.stage_plan := p_definition->'stage_plan'; p.allowed_versions := p_definition->'allowed_versions';
            p.retry_profile := p_definition->'retry_profile'; p.freshness_profile := p_definition->'freshness_profile';
            p.resource_profile := p_definition->'resource_profile';
            IF p_definition->'definition_version' IS DISTINCT FROM '1'::jsonb THEN PERFORM public.proof_scan_fail('pscan_policy_definition'); END IF;
            p.definition_digest := public.proof_scan_digest('silansia.proof.policy.academic.v1',p_definition);
            p.approval_reference := p_authority; p.created_by_function := 'proof_scan_create_policy'; p.created_at := clock_timestamp();
            INSERT INTO public.proof_scan_policies SELECT p.*;
            PERFORM public.proof_scan_record_event('policy_created',NULL,NULL,NULL,NULL,p.id,NULL,NULL,p.created_at,p_authority,NULL,NULL,NULL);
            RETURN p.id;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_set_head(p_policy uuid, p_incarnation uuid, p_expected_epoch bigint, p_admission text, p_authority text) RETURNS bigint
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE h public.proof_scan_policy_heads%ROWTYPE; at_time timestamptz; policy_xid text; policy_authority text;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            SELECT xmin::text,approval_reference INTO STRICT policy_xid,policy_authority FROM public.proof_scan_policies WHERE id=p_policy;
            IF policy_authority IS DISTINCT FROM p_authority OR policy_xid = pg_current_xact_id()::text OR p_incarnation IS NULL OR p_admission NOT IN ('held','active') OR p_admission IS NULL THEN
                PERFORM public.proof_scan_fail('pscan_head_precondition');
            END IF;
            SELECT * INTO h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' FOR UPDATE;
            at_time := clock_timestamp();
            IF h.id IS NULL THEN
                IF p_expected_epoch IS DISTINCT FROM 0::bigint THEN PERFORM public.proof_scan_fail('pscan_head_epoch'); END IF;
                INSERT INTO public.proof_scan_policy_heads VALUES(gen_random_uuid(),'academic_owned_run',p_policy,p_incarnation,1,p_admission,p_authority,at_time)
                  RETURNING * INTO h;
            ELSE
                IF h.authority_ref IS DISTINCT FROM p_authority OR h.epoch IS DISTINCT FROM p_expected_epoch OR h.admission_incarnation IS DISTINCT FROM p_incarnation OR h.policy_id IS DISTINCT FROM p_policy THEN
                    PERFORM public.proof_scan_fail('pscan_head_epoch');
                END IF;
                UPDATE public.proof_scan_policy_heads SET epoch=epoch+1,admission=p_admission,authority_ref=p_authority,changed_at=at_time WHERE id=h.id RETURNING * INTO h;
            END IF;
            PERFORM public.proof_scan_record_event(CASE WHEN p_admission='active' THEN 'policy_activated' ELSE 'policy_held' END,NULL,NULL,NULL,NULL,h.policy_id,h.epoch,h.admission_incarnation,at_time,p_authority,NULL,NULL,NULL);
            RETURN h.epoch;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_new_job(p_subject uuid, p_origin text, p_at timestamp with time zone, p_authority text) RETURNS uuid
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE q public.proof_scan_subjects%ROWTYPE; p public.proof_scan_policies%ROWTYPE; j public.proof_scan_jobs%ROWTYPE;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=p_subject;
            SELECT * INTO STRICT p FROM public.proof_scan_policies WHERE id=q.policy_id;
            j.id:=gen_random_uuid(); j.subject_id:=q.id; j.policy_id:=q.policy_id; j.admission_incarnation:=q.admission_incarnation;
            j.generation:=q.generation; j.policy_epoch:=q.policy_epoch; j.fence:=1; j.stage_id:='synthetic_stage_v1';
            j.stage_plan_digest:=public.proof_scan_digest('silansia.proof.stage.academic.v1',p.stage_plan);
            j.state:='READY'; j.not_before:=p_at; j.created_at:=p_at; j.changed_at:=p_at; j.attempt_count:=0;
            j.origin:=p_origin; j.requested_by_function:=CASE WHEN p_origin='fresh_submit' THEN 'proof_scan_initialize_subject' ELSE 'proof_scan_rescan_or_dispose' END;
            j.authority_ref:=p_authority;
            INSERT INTO public.proof_scan_jobs SELECT j.*;
            PERFORM public.proof_scan_record_event('job_created',q.id,j.id,NULL,NULL,NULL,NULL,NULL,p_at,p_authority,NULL,NULL,NULL);
            RETURN j.id;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_initialize_subject(p_payment uuid, p_request uuid, p_action uuid, p_descriptor jsonb, p_authority text) RETURNS uuid
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE pay public.payments%ROWTYPE; r public.billing_requests%ROWTYPE; a public.billing_actions%ROWTYPE;
            h public.proof_scan_policy_heads%ROWTYPE; q public.proof_scan_subjects%ROWTYPE; at_time timestamptz; fresh boolean;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            SELECT * INTO STRICT h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' FOR SHARE;
            IF h.authority_ref IS DISTINCT FROM p_authority OR h.admission <> 'active' THEN PERFORM public.proof_scan_fail('pscan_admission'); END IF;
            SELECT (xmin::text=pg_current_xact_id()::text) INTO fresh FROM public.payments WHERE id=p_payment;
            IF fresh IS DISTINCT FROM true THEN PERFORM public.proof_scan_fail('pscan_no_adoption'); END IF;
            SELECT * INTO STRICT pay FROM public.payments WHERE id=p_payment;
            SELECT * INTO STRICT r FROM public.billing_requests WHERE id=p_request AND xmin::text=pg_current_xact_id()::text;
            SELECT * INTO STRICT a FROM public.billing_actions WHERE id=p_action AND xmin::text=pg_current_xact_id()::text;
            IF pay.billing_schema_version IS DISTINCT FROM 1::smallint OR pay.submission_channel IS DISTINCT FROM 'family_upload'
               OR pay.status IS DISTINCT FROM 'pending' OR r.operation <> 'submit'
               OR ROW(r.invoice_id,r.actor_id,r.outcome_payment_id,r.outcome_revision_id) IS DISTINCT FROM ROW(pay.invoice_id,pay.submitted_by,pay.id,pay.invoice_revision_id)
               OR a.action <> 'payment_submitted' OR ROW(a.billing_request_id,a.invoice_id,a.actor_id,a.payment_id,a.revision_id) IS DISTINCT FROM ROW(r.id,pay.invoice_id,pay.submitted_by,pay.id,pay.invoice_revision_id) THEN
                PERFORM public.proof_scan_fail('pscan_initial_binding');
            END IF;
            at_time:=clock_timestamp();
            q.id:=gen_random_uuid(); q.payment_id:=pay.id; q.invoice_id:=pay.invoice_id; q.revision_id:=pay.invoice_revision_id;
            q.submitter_id:=pay.submitted_by; q.proof_reference:=(split_part(pay.proof_path,'/',2))::uuid;
            q.billing_request_id:=r.id; q.billing_action_id:=a.id;
            q.proof_disk:=pay.proof_disk; q.proof_path:=pay.proof_path; q.proof_sha256:=pay.proof_sha256;
            q.proof_size_bytes:=pay.proof_size_bytes; q.proof_mime:=pay.proof_mime; q.descriptor_version:=1;
            IF p_descriptor IS DISTINCT FROM jsonb_build_object('descriptor_version',1,'proof_reference',q.proof_reference,
                'proof_disk',q.proof_disk,'proof_path',q.proof_path,'proof_sha256',q.proof_sha256,'proof_size_bytes',q.proof_size_bytes,'proof_mime',q.proof_mime) THEN
                PERFORM public.proof_scan_fail('pscan_descriptor');
            END IF;
            q.subject_digest:=public.proof_scan_subject_digest(to_jsonb(q));
            q.created_at:=at_time; q.changed_at:=at_time; q.generation:=1; q.state_version:=1;
            q.policy_epoch:=h.epoch; q.policy_id:=h.policy_id; q.admission_incarnation:=h.admission_incarnation;
            q.state:='QUEUED'; q.held_reason:='queued'; q.security_hold:=false;
            INSERT INTO public.proof_scan_subjects SELECT q.*;
            PERFORM public.proof_scan_record_event('initial_subject_bound',q.id,NULL,NULL,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            PERFORM public.proof_scan_new_job(q.id,'fresh_submit',at_time,p_authority);
            RETURN q.id;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_lock_job(p_job uuid, p_authority text, p_require_admission boolean) RETURNS void
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE h public.proof_scan_policy_heads%ROWTYPE; q public.proof_scan_subjects%ROWTYPE; j public.proof_scan_jobs%ROWTYPE; subject_key uuid;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            SELECT subject_id INTO STRICT subject_key FROM public.proof_scan_jobs WHERE id=p_job;
            SELECT * INTO STRICT h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' FOR SHARE;
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=subject_key FOR UPDATE;
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE id=p_job FOR UPDATE;
            IF h.authority_ref IS DISTINCT FROM p_authority OR q.state='RETIRED'
               OR ROW(q.policy_id,q.admission_incarnation) IS DISTINCT FROM ROW(h.policy_id,h.admission_incarnation)
               OR p_require_admission IS NULL
               OR (p_require_admission AND (h.admission <> 'active' OR q.policy_epoch<>h.epoch))
               OR ROW(j.subject_id,j.policy_id,j.policy_epoch,j.admission_incarnation,j.generation)
                  IS DISTINCT FROM ROW(q.id,q.policy_id,q.policy_epoch,q.admission_incarnation,q.generation) THEN
                PERFORM public.proof_scan_fail('pscan_assignment_scope');
            END IF;
            IF j.current_attempt_id IS NOT NULL THEN
                PERFORM 1 FROM public.proof_scan_attempts WHERE id=j.current_attempt_id AND job_id=j.id FOR UPDATE;
                IF NOT FOUND THEN PERFORM public.proof_scan_fail('pscan_current_attempt'); END IF;
            END IF;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_projection(p_subject uuid, p_at timestamp with time zone, p_ignore_hold boolean) RETURNS text
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE q public.proof_scan_subjects%ROWTYPE; h public.proof_scan_policy_heads%ROWTYPE;
            j public.proof_scan_jobs%ROWTYPE; a public.proof_scan_attempts%ROWTYPE; v public.proof_scan_verdicts%ROWTYPE;
        BEGIN
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=p_subject;
            IF q.state='RETIRED' THEN RETURN 'RETIRED'; END IF;
            IF q.security_hold AND NOT p_ignore_hold THEN RETURN CASE WHEN q.hold_reason='infected' THEN 'INFECTED_HELD' ELSE 'HELD' END; END IF;
            SELECT * INTO h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run';
            IF h.id IS NULL OR h.authority_ref IS DISTINCT FROM public.proof_scan_authority() OR h.admission<>'active'
               OR ROW(q.policy_id,q.policy_epoch,q.admission_incarnation) IS DISTINCT FROM ROW(h.policy_id,h.epoch,h.admission_incarnation) THEN RETURN 'STALE_HELD'; END IF;
            SELECT * INTO j FROM public.proof_scan_jobs WHERE subject_id=q.id AND generation=q.generation AND stage_id='synthetic_stage_v1';
            IF j.id IS NULL OR ROW(j.policy_id,j.policy_epoch,j.admission_incarnation) IS DISTINCT FROM ROW(q.policy_id,q.policy_epoch,q.admission_incarnation) THEN RETURN 'ERROR_HELD'; END IF;
            IF j.state='READY' THEN RETURN 'QUEUED'; END IF;
            IF j.state='LEASED' THEN RETURN 'SCANNING'; END IF;
            SELECT * INTO a FROM public.proof_scan_attempts WHERE id=j.current_attempt_id AND job_id=j.id;
            SELECT * INTO v FROM public.proof_scan_verdicts WHERE attempt_id=a.id;
            IF v.id IS NULL THEN RETURN 'ERROR_HELD'; END IF;
            IF a.state<>'ACCEPTED' OR ROW(v.subject_id,v.job_id,v.generation,v.fence,v.policy_id,v.policy_epoch,v.admission_incarnation,v.identity_epoch)
               IS DISTINCT FROM ROW(q.id,j.id,q.generation,j.fence,q.policy_id,q.policy_epoch,q.admission_incarnation,a.identity_epoch)
               OR ROW(v.subject_digest,v.observed_sha256,v.observed_size_bytes,v.observed_mime)
               IS DISTINCT FROM ROW(q.subject_digest,q.proof_sha256,q.proof_size_bytes,q.proof_mime) THEN RETURN 'ERROR_HELD'; END IF;
            IF v.verdict='infected' THEN RETURN 'INFECTED_HELD'; END IF;
            IF v.verdict='unknown' THEN RETURN 'UNKNOWN_HELD'; END IF;
            IF v.verdict='error' THEN RETURN 'ERROR_HELD'; END IF;
            IF j.state<>'COMPLETED' OR p_at >= v.received_at + interval '120 seconds' THEN RETURN 'STALE_HELD'; END IF;
            RETURN 'CLEAN_ELIGIBLE';
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_project(p_subject uuid, p_at timestamp with time zone, p_authority text) RETURNS void
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE next_state text;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            next_state:=public.proof_scan_projection(p_subject,p_at,false);
            UPDATE public.proof_scan_subjects SET state=next_state,held_reason=lower(next_state),
                state_version=state_version+1,changed_at=p_at WHERE id=p_subject;
            PERFORM public.proof_scan_record_event('subject_transition',p_subject,NULL,NULL,NULL,NULL,NULL,NULL,p_at,p_authority,NULL,NULL,NULL);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_claim_attempt(p_job uuid, p_identity_epoch bigint, p_audience text, p_authority text) RETURNS jsonb
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE j public.proof_scan_jobs%ROWTYPE; q public.proof_scan_subjects%ROWTYPE; a public.proof_scan_attempts%ROWTYPE; at_time timestamptz;
        BEGIN
            PERFORM public.proof_scan_lock_job(p_job,p_authority,true);
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE id=p_job;
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=j.subject_id;
            at_time:=clock_timestamp();
            IF j.state NOT IN ('READY','RETRY_WAIT') OR j.not_before>at_time OR j.attempt_count>=2
               OR p_identity_epoch IS NULL OR p_identity_epoch<1 OR p_audience IS DISTINCT FROM p_authority THEN
                PERFORM public.proof_scan_fail('pscan_claim_precondition');
            END IF;
            a.id:=gen_random_uuid(); a.job_id:=j.id; a.challenge_id:=gen_random_uuid(); a.admission_incarnation:=j.admission_incarnation;
            a.attempt_no:=j.attempt_count+1; a.generation:=j.generation; a.fence:=a.attempt_no;
            a.identity_epoch:=p_identity_epoch; a.scanner_identity:='synthetic_mock'; a.deployment_audience:=p_audience;
            a.state:='LEASED'; a.leased_at:=at_time; a.lease_deadline:=at_time+interval '30 seconds';
            UPDATE public.proof_scan_jobs SET state='LEASED',attempt_count=a.attempt_no,fence=a.fence,
                current_attempt_id=a.id,changed_at=at_time WHERE id=j.id;
            INSERT INTO public.proof_scan_attempts SELECT a.*;
            PERFORM public.proof_scan_project(q.id,at_time,p_authority);
            PERFORM public.proof_scan_record_event('attempt_claimed',q.id,j.id,a.id,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            RETURN jsonb_build_object('is_simulation',true,'subject_id',q.id,'payment_id',q.payment_id,'invoice_id',q.invoice_id,
                'revision_id',q.revision_id,'subject_digest',q.subject_digest,'proof_reference',q.proof_reference,
                'proof_sha256',q.proof_sha256,'proof_size_bytes',q.proof_size_bytes,'proof_mime',q.proof_mime,
                'job_id',j.id,'attempt_id',a.id,'challenge_id',a.challenge_id,'generation',a.generation,'fence',a.fence,
                'policy_id',j.policy_id,'policy_epoch',j.policy_epoch,'stage_id',j.stage_id,
                'admission_incarnation',a.admission_incarnation,'identity_epoch',a.identity_epoch,
                'scanner_identity',a.scanner_identity,'deployment_audience',a.deployment_audience,
                'lease_deadline',a.lease_deadline,'adapter_timeout_seconds',5);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_start_or_expire_attempt(p_attempt uuid, p_mode text, p_authority text) RETURNS void
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE a public.proof_scan_attempts%ROWTYPE; j public.proof_scan_jobs%ROWTYPE; job_key uuid; at_time timestamptz; terminal_state text; reason text;
        BEGIN
            SELECT job_id INTO STRICT job_key FROM public.proof_scan_attempts WHERE id=p_attempt;
            PERFORM public.proof_scan_lock_job(job_key,p_authority,p_mode='start');
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE id=job_key;
            SELECT * INTO STRICT a FROM public.proof_scan_attempts WHERE id=p_attempt FOR UPDATE;
            at_time:=clock_timestamp();
            IF j.current_attempt_id IS DISTINCT FROM a.id OR j.state<>'LEASED' OR NOT public.proof_scan_active(a.state) THEN
                PERFORM public.proof_scan_fail('pscan_active_attempt_required');
            END IF;
            IF p_mode='start' THEN
                IF a.state<>'LEASED' OR at_time>=a.lease_deadline THEN PERFORM public.proof_scan_fail('pscan_lease'); END IF;
                UPDATE public.proof_scan_attempts SET state='RUNNING',started_at=at_time WHERE id=a.id;
                PERFORM public.proof_scan_record_event('attempt_started',j.subject_id,j.id,a.id,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
                RETURN;
            END IF;
            IF p_mode='expire' AND at_time>=a.lease_deadline THEN terminal_state:='EXPIRED'; reason:='lease_expired';
            ELSIF p_mode='fail' AND at_time<a.lease_deadline THEN terminal_state:='FAILED'; reason:='adapter_failed';
            ELSE PERFORM public.proof_scan_fail('pscan_attempt_transition'); END IF;
            UPDATE public.proof_scan_attempts SET state=terminal_state,completed_at=at_time,failure_code=reason WHERE id=a.id;
            UPDATE public.proof_scan_jobs SET state=CASE WHEN attempt_count<2 THEN 'RETRY_WAIT' ELSE 'DEAD_LETTER' END,
                terminal_reason=CASE WHEN attempt_count<2 THEN NULL ELSE reason END,
                not_before=at_time+interval '1 second',changed_at=at_time WHERE id=j.id;
            PERFORM public.proof_scan_project(j.subject_id,at_time,p_authority);
            PERFORM public.proof_scan_record_event(CASE WHEN terminal_state='EXPIRED' THEN 'attempt_expired' ELSE 'attempt_failed' END,j.subject_id,j.id,a.id,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_accept_result(p_envelope jsonb, p_authority text) RETURNS jsonb
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE q public.proof_scan_subjects%ROWTYPE; j public.proof_scan_jobs%ROWTYPE; a public.proof_scan_attempts%ROWTYPE;
            v public.proof_scan_verdicts%ROWTYPE; prior public.proof_scan_verdicts%ROWTYPE; h public.proof_scan_policy_heads%ROWTYPE;
            at_time timestamptz; envelope_hash text; attempt_key uuid; observed_time timestamptz; expected jsonb;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            IF jsonb_typeof(p_envelope) IS DISTINCT FROM 'object' THEN PERFORM public.proof_scan_fail('pscan_result_envelope'); END IF;
            envelope_hash:=public.proof_scan_digest('silansia.proof.result.academic.v1',p_envelope);
            attempt_key:=(p_envelope->>'attempt_id')::uuid;
            SELECT * INTO prior FROM public.proof_scan_verdicts WHERE attempt_id=attempt_key
                OR (scanner_identity=p_envelope->>'scanner_identity' AND result_id=(p_envelope->>'result_id')::uuid);
            IF FOUND THEN
                IF prior.envelope_digest IS DISTINCT FROM envelope_hash OR prior.attempt_id IS DISTINCT FROM attempt_key
                   OR p_envelope->>'deployment_audience' IS DISTINCT FROM p_authority THEN PERFORM public.proof_scan_fail('pscan_result_conflict'); END IF;
                RETURN jsonb_build_object('verdict_id',prior.id,'historical_ack',true,'is_simulation',true);
            END IF;
            SELECT * INTO STRICT a FROM public.proof_scan_attempts WHERE id=attempt_key;
            SELECT * INTO STRICT h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' FOR SHARE;
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=(SELECT subject_id FROM public.proof_scan_jobs WHERE id=a.job_id) FOR UPDATE;
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE id=a.job_id FOR UPDATE;
            SELECT * INTO STRICT a FROM public.proof_scan_attempts WHERE id=attempt_key FOR UPDATE;
            -- A concurrent identical delivery may have committed while locks were awaited.
            SELECT * INTO prior FROM public.proof_scan_verdicts WHERE attempt_id=a.id;
            IF FOUND THEN
                IF prior.envelope_digest IS DISTINCT FROM envelope_hash THEN PERFORM public.proof_scan_fail('pscan_result_conflict'); END IF;
                RETURN jsonb_build_object('verdict_id',prior.id,'historical_ack',true,'is_simulation',true);
            END IF;
            IF h.authority_ref IS DISTINCT FROM p_authority OR h.admission<>'active' OR q.state='RETIRED'
               OR ROW(q.policy_id,q.policy_epoch,q.admission_incarnation) IS DISTINCT FROM ROW(h.policy_id,h.epoch,h.admission_incarnation)
               OR ROW(j.subject_id,j.policy_id,j.policy_epoch,j.admission_incarnation,j.generation)
                  IS DISTINCT FROM ROW(q.id,q.policy_id,q.policy_epoch,q.admission_incarnation,q.generation) THEN
                PERFORM public.proof_scan_fail('pscan_assignment_scope');
            END IF;
            at_time:=clock_timestamp();
            IF j.current_attempt_id IS DISTINCT FROM a.id OR j.state<>'LEASED' OR NOT public.proof_scan_active(a.state)
               OR at_time>=a.lease_deadline THEN PERFORM public.proof_scan_fail('pscan_lease'); END IF;
            IF (p_envelope->>'observed_at') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(.[0-9]{1,6})?(Z|[+-][0-9]{2}:[0-9]{2})$'
               OR p_envelope->>'observed_at' IS NULL THEN PERFORM public.proof_scan_fail('pscan_result_clock'); END IF;
            observed_time:=(p_envelope->>'observed_at')::timestamptz;
            expected:=jsonb_build_object('is_simulation',true,'result_id',(p_envelope->>'result_id')::uuid,
                'verdict',p_envelope->>'verdict','reason_code','simulation_' || (p_envelope->>'verdict'),'observed_at',p_envelope->>'observed_at',
                'subject_id',q.id,'payment_id',q.payment_id,'invoice_id',q.invoice_id,'revision_id',q.revision_id,
                'subject_digest',q.subject_digest,'proof_reference',q.proof_reference,'proof_sha256',q.proof_sha256,'proof_size_bytes',q.proof_size_bytes,'proof_mime',q.proof_mime,
                'job_id',j.id,'attempt_id',a.id,'challenge_id',a.challenge_id,'generation',a.generation,'fence',a.fence,
                'policy_id',j.policy_id,'policy_epoch',j.policy_epoch,'stage_id',j.stage_id,'admission_incarnation',a.admission_incarnation,
                'identity_epoch',a.identity_epoch,'scanner_identity',a.scanner_identity,'deployment_audience',a.deployment_audience,
                'engine_id','synthetic_mock','engine_version','1','signature_set_id','not_applicable_simulation',
                'signature_version','not_applicable_simulation','auth_scheme_version','trusted_in_process_v1','key_epoch_reference','not_applicable_owned_runtime');
            IF p_envelope IS DISTINCT FROM expected OR observed_time<a.leased_at OR observed_time>at_time
               OR NOT isfinite(observed_time) THEN PERFORM public.proof_scan_fail('pscan_result_binding'); END IF;
            v.id:=gen_random_uuid(); v.result_id:=(p_envelope->>'result_id')::uuid; v.attempt_id:=a.id; v.job_id:=j.id;
            v.subject_id:=q.id; v.policy_id:=j.policy_id; v.admission_incarnation:=a.admission_incarnation;
            v.generation:=a.generation; v.fence:=a.fence; v.policy_epoch:=j.policy_epoch; v.identity_epoch:=a.identity_epoch;
            v.observed_size_bytes:=q.proof_size_bytes; v.subject_digest:=q.subject_digest; v.observed_sha256:=q.proof_sha256;
            v.envelope_digest:=envelope_hash; v.stage_id:=j.stage_id; v.reason_code:=p_envelope->>'reason_code'; v.observed_mime:=q.proof_mime;
            v.verdict:=p_envelope->>'verdict'; v.scanner_identity:=a.scanner_identity; v.engine_id:='synthetic_mock'; v.engine_version:='1';
            v.signature_set_id:='not_applicable_simulation'; v.signature_version:='not_applicable_simulation';
            v.observed_at:=observed_time; v.received_at:=at_time; v.auth_scheme_version:='trusted_in_process_v1'; v.key_epoch_reference:='not_applicable_owned_runtime';
            INSERT INTO public.proof_scan_verdicts SELECT v.*;
            UPDATE public.proof_scan_attempts SET state='ACCEPTED',completed_at=at_time WHERE id=a.id;
            UPDATE public.proof_scan_jobs SET state=CASE WHEN v.verdict IN ('clean','infected') THEN 'COMPLETED' WHEN attempt_count<2 THEN 'RETRY_WAIT' ELSE 'DEAD_LETTER' END,
                terminal_reason=CASE WHEN v.verdict IN ('clean','infected') OR attempt_count=2 THEN v.verdict ELSE NULL END,
                not_before=at_time+interval '1 second',changed_at=at_time WHERE id=j.id;
            IF v.verdict='infected' THEN
                UPDATE public.proof_scan_subjects SET security_hold=true,hold_authority_ref=p_authority,hold_reason='infected',
                    state='INFECTED_HELD',held_reason='infected_held',state_version=state_version+1,changed_at=at_time WHERE id=q.id;
                PERFORM public.proof_scan_record_event('subject_transition',q.id,NULL,NULL,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            ELSE
                PERFORM public.proof_scan_project(q.id,at_time,p_authority);
            END IF;
            PERFORM public.proof_scan_record_event('result_accepted',q.id,j.id,a.id,v.id,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            RETURN jsonb_build_object('verdict_id',v.id,'historical_ack',false,'is_simulation',true);
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_rescan_or_dispose(p_subject uuid, p_expected_version bigint, p_mode text, p_authority text) RETURNS bigint
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE h public.proof_scan_policy_heads%ROWTYPE; q public.proof_scan_subjects%ROWTYPE;
            j public.proof_scan_jobs%ROWTYPE; a public.proof_scan_attempts%ROWTYPE; at_time timestamptz; next_state text;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            SELECT * INTO STRICT h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' FOR SHARE;
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=p_subject FOR UPDATE;
            IF h.authority_ref IS DISTINCT FROM p_authority OR h.admission<>'active' OR q.state='RETIRED' OR q.state_version IS DISTINCT FROM p_expected_version
               OR q.admission_incarnation IS DISTINCT FROM h.admission_incarnation OR q.policy_id IS DISTINCT FROM h.policy_id THEN
                PERFORM public.proof_scan_fail('pscan_rescan_scope');
            END IF;
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE subject_id=q.id AND generation=q.generation AND stage_id='synthetic_stage_v1' FOR UPDATE;
            IF j.current_attempt_id IS NOT NULL THEN
                SELECT * INTO STRICT a FROM public.proof_scan_attempts WHERE id=j.current_attempt_id FOR UPDATE;
            END IF;
            at_time:=clock_timestamp();
            IF p_mode='rescan' THEN
                IF public.proof_scan_active(a.state) THEN
                    UPDATE public.proof_scan_attempts SET state='SUPERSEDED',completed_at=at_time,failure_code='rescan' WHERE id=a.id;
                END IF;
                IF j.state IN ('READY','LEASED','RETRY_WAIT') THEN
                    UPDATE public.proof_scan_jobs SET state='SUPERSEDED',terminal_reason='rescan',changed_at=at_time WHERE id=j.id;
                    PERFORM public.proof_scan_record_event('job_superseded',q.id,j.id,a.id,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
                END IF;
                next_state:=CASE WHEN q.security_hold THEN CASE WHEN q.hold_reason='infected' THEN 'INFECTED_HELD' ELSE 'HELD' END ELSE 'QUEUED' END;
                UPDATE public.proof_scan_subjects SET generation=generation+1,state_version=state_version+1,
                    policy_epoch=h.epoch,state=next_state,held_reason=lower(next_state),changed_at=at_time WHERE id=q.id RETURNING * INTO q;
                PERFORM public.proof_scan_new_job(q.id,'authorized_rescan',at_time,p_authority);
                PERFORM public.proof_scan_record_event('rescan_requested',q.id,NULL,NULL,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            ELSIF p_mode='dispose_hold' THEN
                next_state:=public.proof_scan_projection(q.id,at_time,true);
                IF NOT q.security_hold OR next_state IN ('HELD','INFECTED_HELD','RETIRED')
                   OR ROW(q.policy_id,q.policy_epoch,q.admission_incarnation) IS DISTINCT FROM ROW(h.policy_id,h.epoch,h.admission_incarnation) THEN
                    PERFORM public.proof_scan_fail('pscan_hold_disposition');
                END IF;
                UPDATE public.proof_scan_subjects SET security_hold=false,hold_authority_ref=NULL,hold_reason=NULL,
                    state=next_state,held_reason=lower(next_state),state_version=state_version+1,changed_at=at_time WHERE id=q.id RETURNING * INTO q;
                PERFORM public.proof_scan_record_event('security_hold_disposed',q.id,NULL,NULL,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            ELSE PERFORM public.proof_scan_fail('pscan_rescan_mode'); END IF;
            PERFORM public.proof_scan_record_event('subject_transition',q.id,NULL,NULL,NULL,NULL,NULL,NULL,at_time,p_authority,NULL,NULL,NULL);
            RETURN q.generation;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_append_audit(p_subject uuid, p_expected_version bigint, p_kind text, p_principal uuid, p_audience text, p_auth_binding text, p_descriptor jsonb, p_authority text) RETURNS jsonb
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE h public.proof_scan_policy_heads%ROWTYPE; q public.proof_scan_subjects%ROWTYPE;
            j public.proof_scan_jobs%ROWTYPE; v public.proof_scan_verdicts%ROWTYPE; invoice_row record; actor_row record; event_id uuid; at_time timestamptz;
        BEGIN
            PERFORM public.proof_scan_environment(p_authority);
            IF p_kind IS NULL OR p_kind NOT IN ('protected_get_granted','verify_use_granted')
               OR p_audience IS NULL OR p_audience !~ '^[0-9a-f]{64}$' OR p_auth_binding IS NULL OR p_auth_binding !~ '^[0-9a-f]{64}$' THEN
                PERFORM public.proof_scan_fail('pscan_grant_binding');
            END IF;
            -- The future caller holds all L_F locks first. GET takes Q SHARE only; no Q upgrade.
            SELECT * INTO STRICT h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run' FOR SHARE;
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=p_subject FOR SHARE;
            at_time:=clock_timestamp();
            IF q.state_version IS DISTINCT FROM p_expected_version OR q.state<>'CLEAN_ELIGIBLE'
               OR public.proof_scan_projection(q.id,at_time,false)<>'CLEAN_ELIGIBLE'
               OR p_descriptor IS DISTINCT FROM jsonb_build_object('descriptor_version',q.descriptor_version,
                  'proof_reference',q.proof_reference,'proof_disk',q.proof_disk,'proof_path',q.proof_path,
                  'proof_sha256',q.proof_sha256,'proof_size_bytes',q.proof_size_bytes,'proof_mime',q.proof_mime) THEN
                PERFORM public.proof_scan_fail('pscan_use_unavailable');
            END IF;
            SELECT i.*,e.family_id AS current_family INTO STRICT invoice_row FROM public.invoices i JOIN public.elderlies e ON e.id=i.elderly_id WHERE i.id=q.invoice_id;
            SELECT id,role,is_active INTO STRICT actor_row FROM public.users WHERE id=p_principal;
            IF NOT actor_row.is_active OR actor_row.role NOT IN ('admin','keluarga')
               OR (actor_row.role='keluarga' AND (actor_row.id IS DISTINCT FROM invoice_row.current_family OR actor_row.id IS DISTINCT FROM invoice_row.bill_to_family_id))
               OR (p_kind='verify_use_granted' AND (actor_row.role<>'admin' OR invoice_row.status<>'unpaid'
                  OR invoice_row.current_revision_id IS DISTINCT FROM q.revision_id OR NOT EXISTS (
                     SELECT 1 FROM public.payments p WHERE p.id=q.payment_id AND p.status='pending' AND p.amount=invoice_row.amount
                        AND p.invoice_id=q.invoice_id AND p.invoice_revision_id=q.revision_id))) THEN
                PERFORM public.proof_scan_fail('pscan_use_authorization');
            END IF;
            SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE subject_id=q.id AND generation=q.generation AND stage_id='synthetic_stage_v1';
            SELECT * INTO STRICT v FROM public.proof_scan_verdicts WHERE attempt_id=j.current_attempt_id;
            event_id:=public.proof_scan_record_event(p_kind,q.id,j.id,v.attempt_id,v.id,NULL,NULL,NULL,at_time,p_authority,p_principal,p_audience,p_auth_binding);
            RETURN jsonb_build_object('audit_id',event_id,'subject_digest',q.subject_digest,'state_version',q.state_version,'is_simulation',true);
            -- A return value is NOT a committed grant: caller must COMMIT before releasing prepared B.
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_guard_row() RETURNS trigger
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE old_data jsonb; new_data jsonb;
        BEGIN
            PERFORM public.proof_scan_environment(public.proof_scan_authority());
            IF TG_OP='DELETE' THEN PERFORM public.proof_scan_fail('pscan_no_delete'); END IF;
            new_data:=to_jsonb(NEW);
            IF TG_OP='INSERT' THEN
                CASE TG_TABLE_NAME
                WHEN 'proof_scan_subjects' THEN
                    IF NEW.state<>'QUEUED' OR NEW.generation<>1 OR NEW.state_version<>1 OR NEW.security_hold THEN PERFORM public.proof_scan_fail('pscan_subject_initial'); END IF;
            IF NOT EXISTS (SELECT 1 FROM public.payments p WHERE p.id=NEW.payment_id AND p.xmin::text=pg_current_xact_id()::text)
               OR NOT EXISTS (SELECT 1 FROM public.billing_requests r WHERE r.id=NEW.billing_request_id AND r.xmin::text=pg_current_xact_id()::text)
               OR NOT EXISTS (SELECT 1 FROM public.billing_actions b WHERE b.id=NEW.billing_action_id AND b.xmin::text=pg_current_xact_id()::text) THEN
                PERFORM public.proof_scan_fail('pscan_no_adoption');
            END IF;
                WHEN 'proof_scan_jobs' THEN
                    IF NEW.state<>'READY' OR NEW.attempt_count<>0 OR NEW.fence<>1 THEN PERFORM public.proof_scan_fail('pscan_job_initial'); END IF;
                WHEN 'proof_scan_attempts' THEN
                    IF NEW.state<>'LEASED' THEN PERFORM public.proof_scan_fail('pscan_attempt_initial'); END IF;
                ELSE NULL;
                END CASE;
                RETURN NEW;
            END IF;
            old_data:=to_jsonb(OLD);
            IF TG_TABLE_NAME IN ('proof_scan_policies','proof_scan_verdicts','proof_scan_audit_events') THEN
                PERFORM public.proof_scan_fail('pscan_append_only');
            ELSIF TG_TABLE_NAME='proof_scan_policy_heads' THEN
                IF old_data-ARRAY['epoch','admission','changed_at'] IS DISTINCT FROM new_data-ARRAY['epoch','admission','changed_at']
                   OR NEW.epoch<>OLD.epoch+1 OR NEW.changed_at<OLD.changed_at THEN PERFORM public.proof_scan_fail('pscan_head_immutable'); END IF;
            ELSIF TG_TABLE_NAME='proof_scan_subjects' THEN
                IF old_data-ARRAY['generation','state_version','policy_epoch','state','held_reason','security_hold','hold_authority_ref','hold_reason','changed_at']
                   IS DISTINCT FROM new_data-ARRAY['generation','state_version','policy_epoch','state','held_reason','security_hold','hold_authority_ref','hold_reason','changed_at']
                   OR OLD.state='RETIRED' OR NEW.state_version<>OLD.state_version+1 OR NEW.generation NOT IN (OLD.generation,OLD.generation+1)
                   OR NEW.policy_epoch<OLD.policy_epoch OR (NEW.generation=OLD.generation AND NEW.policy_epoch<>OLD.policy_epoch)
                   OR (NEW.generation<>OLD.generation AND ROW(NEW.security_hold,NEW.hold_authority_ref,NEW.hold_reason) IS DISTINCT FROM ROW(OLD.security_hold,OLD.hold_authority_ref,OLD.hold_reason))
                   OR NEW.changed_at<OLD.changed_at THEN PERFORM public.proof_scan_fail('pscan_subject_immutable'); END IF;
            ELSIF TG_TABLE_NAME='proof_scan_jobs' THEN
                IF old_data-ARRAY['state','fence','not_before','changed_at','attempt_count','current_attempt_id','terminal_reason']
                   IS DISTINCT FROM new_data-ARRAY['state','fence','not_before','changed_at','attempt_count','current_attempt_id','terminal_reason']
                   OR OLD.state IN ('COMPLETED','DEAD_LETTER','SUPERSEDED') OR NEW.changed_at<OLD.changed_at
                   OR NOT ((OLD.state IN ('READY','RETRY_WAIT') AND NEW.state='LEASED' AND NEW.attempt_count=OLD.attempt_count+1 AND NEW.current_attempt_id IS DISTINCT FROM OLD.current_attempt_id)
                        OR (OLD.state='LEASED' AND NEW.state IN ('COMPLETED','RETRY_WAIT','DEAD_LETTER') AND NEW.attempt_count=OLD.attempt_count AND NEW.current_attempt_id=OLD.current_attempt_id)
                        OR (NEW.state='SUPERSEDED' AND NEW.attempt_count=OLD.attempt_count AND NEW.current_attempt_id IS NOT DISTINCT FROM OLD.current_attempt_id)) THEN
                    PERFORM public.proof_scan_fail('pscan_job_transition');
                END IF;
            ELSIF TG_TABLE_NAME='proof_scan_attempts' THEN
                IF old_data-ARRAY['state','started_at','completed_at','failure_code'] IS DISTINCT FROM new_data-ARRAY['state','started_at','completed_at','failure_code']
                   OR NOT public.proof_scan_active(OLD.state)
                   OR NOT ((OLD.state='LEASED' AND NEW.state='RUNNING') OR NEW.state IN ('ACCEPTED','FAILED','EXPIRED','SUPERSEDED'))
                   OR (OLD.started_at IS NOT NULL AND NEW.started_at IS DISTINCT FROM OLD.started_at)
                   OR (NEW.state IN ('ACCEPTED','FAILED') AND NEW.completed_at>=OLD.lease_deadline)
                   OR (NEW.state='EXPIRED' AND NEW.completed_at<OLD.lease_deadline) THEN PERFORM public.proof_scan_fail('pscan_attempt_immutable'); END IF;
            ELSE PERFORM public.proof_scan_fail('pscan_unknown_table'); END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_guard_truncate() RETURNS trigger
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        BEGIN PERFORM public.proof_scan_fail('pscan_no_truncate'); RETURN NULL; END;
        $fn$;

        CREATE FUNCTION public.proof_scan_check_subject(p_subject uuid) RETURNS void
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE q public.proof_scan_subjects%ROWTYPE; p public.proof_scan_policies%ROWTYPE; j public.proof_scan_jobs%ROWTYPE; a public.proof_scan_attempts%ROWTYPE; v public.proof_scan_verdicts%ROWTYPE; stage_count integer;
        BEGIN
            SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=p_subject;
            SELECT * INTO STRICT p FROM public.proof_scan_policies WHERE id=q.policy_id;
            IF q.subject_digest IS DISTINCT FROM public.proof_scan_subject_digest(to_jsonb(q))
               OR NOT EXISTS (SELECT 1 FROM public.payments x JOIN public.billing_requests r ON r.id=q.billing_request_id
                   JOIN public.billing_actions b ON b.id=q.billing_action_id
                   WHERE x.id=q.payment_id AND x.billing_schema_version=1 AND x.submission_channel='family_upload'
                     AND ROW(x.invoice_id,x.invoice_revision_id,x.submitted_by,x.proof_disk,x.proof_path,x.proof_sha256,x.proof_size_bytes,x.proof_mime)
                      IS NOT DISTINCT FROM ROW(q.invoice_id,q.revision_id,q.submitter_id,q.proof_disk,q.proof_path,q.proof_sha256,q.proof_size_bytes,q.proof_mime)
                     AND ROW(r.operation,r.invoice_id,r.actor_id,r.outcome_payment_id,r.outcome_revision_id)
                      IS NOT DISTINCT FROM ROW('submit'::varchar,q.invoice_id,q.submitter_id,q.payment_id,q.revision_id)
                     AND ROW(b.action,b.billing_request_id,b.invoice_id,b.actor_id,b.payment_id,b.revision_id)
                      IS NOT DISTINCT FROM ROW('payment_submitted'::varchar,r.id,q.invoice_id,q.submitter_id,q.payment_id,q.revision_id)) THEN
                PERFORM public.proof_scan_fail('pscan_subject_binding');
            END IF;
            SELECT count(*) INTO stage_count FROM public.proof_scan_jobs WHERE subject_id=q.id AND generation=q.generation;
            IF stage_count<>1 OR q.state IS DISTINCT FROM public.proof_scan_projection(q.id,q.changed_at,false) THEN
                PERFORM public.proof_scan_fail('pscan_subject_projection');
            END IF;
            IF NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.subject_id=q.id
                AND e.subject_state_version=q.state_version AND e.subject_state=q.state
                AND e.event_type=CASE WHEN q.state_version=1 THEN 'initial_subject_bound' ELSE 'subject_transition' END
                AND e.recorded_at=q.changed_at AND e.generation=q.generation AND e.policy_epoch=q.policy_epoch
                AND e.descriptor_digest=q.subject_digest AND e.admission_incarnation=q.admission_incarnation AND e.policy_id=q.policy_id) THEN
                PERFORM public.proof_scan_fail('pscan_mutation_audit');
            END IF;
            FOR j IN SELECT * FROM public.proof_scan_jobs WHERE subject_id=q.id ORDER BY id LOOP
                IF j.generation>q.generation OR j.policy_id<>q.policy_id OR j.admission_incarnation<>q.admission_incarnation
                   OR j.stage_plan_digest IS DISTINCT FROM public.proof_scan_digest('silansia.proof.stage.academic.v1',p.stage_plan)
                   OR (j.generation=q.generation AND j.policy_epoch<>q.policy_epoch)
                   OR (j.generation<q.generation AND j.state NOT IN ('COMPLETED','DEAD_LETTER','SUPERSEDED'))
                   OR (SELECT count(*) FROM public.proof_scan_attempts WHERE job_id=j.id)<>j.attempt_count
                   OR NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='job_created' AND e.job_id=j.id
                        AND e.subject_id=q.id AND e.generation=j.generation AND e.policy_epoch=j.policy_epoch AND e.recorded_at=j.created_at) THEN
                    PERFORM public.proof_scan_fail('pscan_job_graph');
                END IF;
                IF j.state='SUPERSEDED' AND NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='job_superseded' AND e.job_id=j.id AND e.recorded_at=j.changed_at) THEN
                    PERFORM public.proof_scan_fail('pscan_job_audit');
                END IF;
                IF j.current_attempt_id IS NOT NULL THEN
                    SELECT * INTO STRICT a FROM public.proof_scan_attempts WHERE job_id=j.id AND id=j.current_attempt_id;
                    IF a.attempt_no<>j.attempt_count OR a.fence<>j.fence OR a.generation<>j.generation
                       OR a.admission_incarnation<>j.admission_incarnation OR (j.state='LEASED') IS DISTINCT FROM public.proof_scan_active(a.state) THEN
                        PERFORM public.proof_scan_fail('pscan_current_attempt');
                    END IF;
                    SELECT * INTO v FROM public.proof_scan_verdicts WHERE attempt_id=a.id;
                    IF (j.state='COMPLETED' AND (v.id IS NULL OR v.verdict NOT IN ('clean','infected') OR j.terminal_reason<>v.verdict))
                       OR (j.state='RETRY_WAIT' AND (a.state NOT IN ('ACCEPTED','FAILED','EXPIRED') OR j.attempt_count>=2 OR j.not_before<>a.completed_at+interval '1 second'
                            OR (a.state='ACCEPTED' AND v.verdict NOT IN ('unknown','error'))))
                       OR (j.state='DEAD_LETTER' AND (a.state NOT IN ('ACCEPTED','FAILED','EXPIRED') OR j.attempt_count<>2 OR j.terminal_reason IS DISTINCT FROM COALESCE(v.verdict,a.failure_code))) THEN
                        PERFORM public.proof_scan_fail('pscan_job_outcome');
                    END IF;
                END IF;
                FOR a IN SELECT * FROM public.proof_scan_attempts WHERE job_id=j.id ORDER BY id LOOP
                    SELECT * INTO v FROM public.proof_scan_verdicts WHERE attempt_id=a.id;
                    IF (public.proof_scan_active(a.state) AND (a.id IS DISTINCT FROM j.current_attempt_id OR j.state<>'LEASED'))
                       OR a.generation<>j.generation OR a.admission_incarnation<>j.admission_incarnation OR a.attempt_no>j.attempt_count
                       OR (a.state='ACCEPTED') IS DISTINCT FROM (v.id IS NOT NULL)
                       OR NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='attempt_claimed' AND e.attempt_id=a.id
                            AND e.job_id=j.id AND e.recorded_at=a.leased_at) THEN PERFORM public.proof_scan_fail('pscan_attempt_graph'); END IF;
                    IF (a.started_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='attempt_started' AND e.attempt_id=a.id AND e.recorded_at=a.started_at))
                       OR (a.state IN ('FAILED','EXPIRED') AND NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e
                            WHERE e.event_type=CASE WHEN a.state='FAILED' THEN 'attempt_failed' ELSE 'attempt_expired' END
                              AND e.attempt_id=a.id AND e.recorded_at=a.completed_at))
                       OR (a.state='SUPERSEDED' AND NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='job_superseded' AND e.attempt_id=a.id AND e.recorded_at=a.completed_at)) THEN
                        PERFORM public.proof_scan_fail('pscan_attempt_audit');
                    END IF;
                    IF v.id IS NOT NULL AND (
                        ROW(v.subject_id,v.job_id,v.policy_id,v.generation,v.policy_epoch,v.stage_id,v.admission_incarnation,v.fence,v.identity_epoch,v.scanner_identity)
                         IS DISTINCT FROM ROW(q.id,j.id,j.policy_id,j.generation,j.policy_epoch,j.stage_id,j.admission_incarnation,a.fence,a.identity_epoch,a.scanner_identity)
                        OR ROW(v.subject_digest,v.observed_sha256,v.observed_size_bytes,v.observed_mime)
                         IS DISTINCT FROM ROW(q.subject_digest,q.proof_sha256,q.proof_size_bytes,q.proof_mime)
                        OR v.received_at IS DISTINCT FROM a.completed_at OR v.received_at>=a.lease_deadline OR v.observed_at<a.leased_at
                        OR NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='result_accepted' AND e.verdict_id=v.id
                            AND e.attempt_id=a.id AND e.recorded_at=v.received_at)) THEN PERFORM public.proof_scan_fail('pscan_verdict_graph'); END IF;
                END LOOP;
            END LOOP;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_guard_audit() RETURNS trigger
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE q public.proof_scan_subjects%ROWTYPE; j public.proof_scan_jobs%ROWTYPE; a public.proof_scan_attempts%ROWTYPE; v public.proof_scan_verdicts%ROWTYPE; actor_row record; invoice_row record;
        BEGIN
            IF TG_OP<>'INSERT' THEN PERFORM public.proof_scan_fail('pscan_append_only'); END IF;
            IF NEW.event_digest IS DISTINCT FROM public.proof_scan_digest('silansia.proof.audit.academic.v1',to_jsonb(NEW)-'event_digest') THEN PERFORM public.proof_scan_fail('pscan_audit_digest'); END IF;
            IF NEW.subject_id IS NOT NULL THEN
                SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=NEW.subject_id;
                IF ROW(NEW.descriptor_digest,NEW.policy_id,NEW.admission_incarnation) IS DISTINCT FROM ROW(q.subject_digest,q.policy_id,q.admission_incarnation)
                   OR NEW.subject_state_version>q.state_version OR NEW.generation>q.generation THEN PERFORM public.proof_scan_fail('pscan_audit_scope'); END IF;
                IF NEW.job_id IS NOT NULL THEN
                    SELECT * INTO STRICT j FROM public.proof_scan_jobs WHERE id=NEW.job_id;
                    IF ROW(j.subject_id,j.generation,j.policy_epoch,j.fence) IS DISTINCT FROM ROW(q.id,NEW.generation,NEW.policy_epoch,NEW.fence) THEN PERFORM public.proof_scan_fail('pscan_audit_scope'); END IF;
                END IF;
                IF NEW.attempt_id IS NOT NULL THEN
                    SELECT * INTO STRICT a FROM public.proof_scan_attempts WHERE id=NEW.attempt_id;
                    IF a.job_id IS DISTINCT FROM j.id OR a.fence IS DISTINCT FROM NEW.fence THEN PERFORM public.proof_scan_fail('pscan_audit_scope'); END IF;
                END IF;
                IF NEW.verdict_id IS NOT NULL THEN
                    SELECT * INTO STRICT v FROM public.proof_scan_verdicts WHERE id=NEW.verdict_id;
                    IF v.attempt_id IS DISTINCT FROM a.id THEN PERFORM public.proof_scan_fail('pscan_audit_scope'); END IF;
                END IF;
                IF (NEW.event_type IN ('initial_subject_bound','subject_transition','rescan_requested','security_hold_disposed') AND (NEW.job_id IS NOT NULL OR NEW.attempt_id IS NOT NULL OR NEW.verdict_id IS NOT NULL))
                   OR (NEW.event_type IN ('job_created','job_superseded') AND NEW.job_id IS NULL)
                   OR (NEW.event_type='job_created' AND (NEW.attempt_id IS NOT NULL OR NEW.verdict_id IS NOT NULL))
                   OR (NEW.event_type IN ('attempt_claimed','attempt_started','attempt_expired','attempt_failed') AND (NEW.attempt_id IS NULL OR NEW.verdict_id IS NOT NULL))
                   OR (NEW.event_type='result_accepted' AND NEW.verdict_id IS NULL) THEN PERFORM public.proof_scan_fail('pscan_audit_shape'); END IF;
                IF NEW.event_type IN ('protected_get_granted','verify_use_granted') THEN
                    SELECT u.id,u.role,u.is_active INTO STRICT actor_row FROM public.users u WHERE u.id=NEW.principal_ref::uuid;
                    SELECT i.*,e.family_id AS current_family INTO STRICT invoice_row FROM public.invoices i JOIN public.elderlies e ON e.id=i.elderly_id WHERE i.id=q.invoice_id;
                    IF NEW.subject_state_version<>q.state_version OR q.state<>'CLEAN_ELIGIBLE' OR q.security_hold
                       OR NEW.subject_state<>q.state OR NEW.generation<>q.generation OR NEW.policy_epoch<>q.policy_epoch
                       OR public.proof_scan_projection(q.id,clock_timestamp(),false)<>'CLEAN_ELIGIBLE'
                       OR j.current_attempt_id IS DISTINCT FROM a.id OR v.verdict IS DISTINCT FROM 'clean'
                       OR NOT actor_row.is_active OR actor_row.role NOT IN ('admin','keluarga')
                       OR (actor_row.role='keluarga' AND (actor_row.id IS DISTINCT FROM invoice_row.current_family OR actor_row.id IS DISTINCT FROM invoice_row.bill_to_family_id))
                       OR (NEW.event_type='verify_use_granted' AND (actor_row.role<>'admin' OR invoice_row.status<>'unpaid' OR invoice_row.current_revision_id IS DISTINCT FROM q.revision_id
                           OR NOT EXISTS (SELECT 1 FROM public.payments p WHERE p.id=q.payment_id AND p.status='pending' AND p.amount=invoice_row.amount))) THEN
                        PERFORM public.proof_scan_fail('pscan_grant_predicate');
                    END IF;
                END IF;
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE FUNCTION public.proof_scan_validate_graph() RETURNS trigger
        LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$

        DECLARE data jsonb; subject_key uuid; p public.proof_scan_policies%ROWTYPE; q public.proof_scan_subjects%ROWTYPE; expected_event text; h public.proof_scan_policy_heads%ROWTYPE; actor_row record; invoice_row record;
        BEGIN
            data:=to_jsonb(NEW);
            IF TG_TABLE_NAME='proof_scan_policies' THEN
                SELECT * INTO STRICT p FROM public.proof_scan_policies WHERE id=NEW.id;
                IF p.definition_digest IS DISTINCT FROM public.proof_scan_digest('silansia.proof.policy.academic.v1',jsonb_build_object(
                    'version',p.version,'definition_version',p.definition_version,'stage_plan',p.stage_plan,'allowed_versions',p.allowed_versions,
                    'retry_profile',p.retry_profile,'freshness_profile',p.freshness_profile,'resource_profile',p.resource_profile))
                   OR NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type='policy_created' AND e.policy_id=p.id AND e.recorded_at=p.created_at) THEN
                    PERFORM public.proof_scan_fail('pscan_policy_graph');
                END IF;
                RETURN NULL;
            ELSIF TG_TABLE_NAME='proof_scan_policy_heads' THEN
                expected_event:=CASE WHEN NEW.admission='active' THEN 'policy_activated' ELSE 'policy_held' END;
                IF NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.event_type=expected_event AND e.policy_id=NEW.policy_id
                    AND e.policy_epoch=NEW.epoch AND e.admission_incarnation=NEW.admission_incarnation AND e.recorded_at=NEW.changed_at) THEN
                    PERFORM public.proof_scan_fail('pscan_head_audit');
                END IF;
                RETURN NULL;
            ELSIF TG_TABLE_NAME='proof_scan_subjects' THEN
                subject_key:=NEW.id;
                IF NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.subject_id=NEW.id AND e.subject_state_version=NEW.state_version
                    AND e.subject_state=NEW.state AND e.recorded_at=NEW.changed_at AND public.proof_scan_mutation(e.event_type)
                    AND e.generation=NEW.generation AND e.policy_epoch=NEW.policy_epoch) THEN PERFORM public.proof_scan_fail('pscan_mutation_audit'); END IF;
                IF TG_OP='UPDATE' THEN
                    IF NEW.generation<>OLD.generation AND NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.subject_id=NEW.id AND e.subject_state_version=NEW.state_version AND e.event_type='rescan_requested') THEN
                        PERFORM public.proof_scan_fail('pscan_rescan_audit');
                    END IF;
                    IF NEW.security_hold AND (NOT OLD.security_hold OR NEW.hold_reason IS DISTINCT FROM OLD.hold_reason)
                       AND NEW.hold_reason='infected' AND NOT EXISTS (SELECT 1 FROM public.proof_scan_verdicts v WHERE v.subject_id=NEW.id AND v.generation=NEW.generation AND v.verdict='infected' AND v.received_at=NEW.changed_at) THEN
                        PERFORM public.proof_scan_fail('pscan_infection_hold');
                    END IF;
                    IF OLD.security_hold AND NOT NEW.security_hold AND NOT EXISTS (SELECT 1 FROM public.proof_scan_audit_events e WHERE e.subject_id=NEW.id AND e.subject_state_version=NEW.state_version AND e.event_type='security_hold_disposed') THEN
                        PERFORM public.proof_scan_fail('pscan_disposition_audit');
                    END IF;
                END IF;
            ELSIF TG_TABLE_NAME='proof_scan_attempts' THEN
                SELECT subject_id INTO STRICT subject_key FROM public.proof_scan_jobs WHERE id=NEW.job_id;
            ELSE
                subject_key:=(data->>'subject_id')::uuid;
                IF TG_TABLE_NAME='proof_scan_audit_events' AND data->>'event_type' IN ('protected_get_granted','verify_use_granted') THEN
                    SELECT * INTO STRICT q FROM public.proof_scan_subjects WHERE id=subject_key;
                    SELECT * INTO STRICT h FROM public.proof_scan_policy_heads WHERE scope_key='academic_owned_run';
                    SELECT u.id,u.role,u.is_active INTO STRICT actor_row FROM public.users u WHERE u.id=(data->>'principal_ref')::uuid;
                    SELECT i.*,e.family_id AS current_family INTO STRICT invoice_row FROM public.invoices i JOIN public.elderlies e ON e.id=i.elderly_id WHERE i.id=q.invoice_id;
                    IF ROW(NEW.subject_state_version,NEW.subject_state,NEW.generation,NEW.policy_id,NEW.policy_epoch,NEW.admission_incarnation,NEW.descriptor_digest)
                         IS DISTINCT FROM ROW(q.state_version,q.state,q.generation,q.policy_id,q.policy_epoch,q.admission_incarnation,q.subject_digest)
                       OR ROW(q.policy_id,q.policy_epoch,q.admission_incarnation) IS DISTINCT FROM ROW(h.policy_id,h.epoch,h.admission_incarnation)
                       OR public.proof_scan_projection(q.id,clock_timestamp(),false)<>'CLEAN_ELIGIBLE'
                       OR NOT actor_row.is_active OR actor_row.role NOT IN ('admin','keluarga')
                       OR (actor_row.role='keluarga' AND (actor_row.id IS DISTINCT FROM invoice_row.current_family OR actor_row.id IS DISTINCT FROM invoice_row.bill_to_family_id))
                       OR (NEW.event_type='verify_use_granted' AND actor_row.role<>'admin') THEN
                        PERFORM public.proof_scan_fail('pscan_final_grant_snapshot');
                    END IF;
                    -- Do not demand pending/unpaid here: an authorized fresh verify changes those
                    -- financial states in this same commit after the BEFORE-INSERT grant predicate.
                END IF;
            END IF;
            IF subject_key IS NOT NULL THEN PERFORM public.proof_scan_check_subject(subject_key); END IF;
            RETURN NULL;
        END;
        $fn$;

        CREATE TRIGGER pss_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_subjects FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER pss_truncate BEFORE TRUNCATE ON public.proof_scan_subjects FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER pss_final AFTER INSERT OR UPDATE ON public.proof_scan_subjects DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER psp_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_policies FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER psp_truncate BEFORE TRUNCATE ON public.proof_scan_policies FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER psp_final AFTER INSERT OR UPDATE ON public.proof_scan_policies DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER psh_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_policy_heads FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER psh_truncate BEFORE TRUNCATE ON public.proof_scan_policy_heads FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER psh_final AFTER INSERT OR UPDATE ON public.proof_scan_policy_heads DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER psj_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_jobs FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER psj_truncate BEFORE TRUNCATE ON public.proof_scan_jobs FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER psj_final AFTER INSERT OR UPDATE ON public.proof_scan_jobs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER psa_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_attempts FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER psa_truncate BEFORE TRUNCATE ON public.proof_scan_attempts FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER psa_final AFTER INSERT OR UPDATE ON public.proof_scan_attempts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER psv_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_verdicts FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER psv_truncate BEFORE TRUNCATE ON public.proof_scan_verdicts FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER psv_final AFTER INSERT OR UPDATE ON public.proof_scan_verdicts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER pse_write BEFORE INSERT OR UPDATE OR DELETE ON public.proof_scan_audit_events FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_row();

        CREATE TRIGGER pse_truncate BEFORE TRUNCATE ON public.proof_scan_audit_events FOR EACH STATEMENT EXECUTE FUNCTION public.proof_scan_guard_truncate();

        CREATE CONSTRAINT TRIGGER pse_final AFTER INSERT OR UPDATE ON public.proof_scan_audit_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.proof_scan_validate_graph();

        CREATE TRIGGER pse_shape BEFORE INSERT ON public.proof_scan_audit_events FOR EACH ROW EXECUTE FUNCTION public.proof_scan_guard_audit();

        REVOKE ALL ON FUNCTION public.proof_scan_fail(text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_digest(text, jsonb) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_active(character varying) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_ready(character varying) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_mutation(character varying) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_policy_shape(jsonb, jsonb, jsonb, jsonb, jsonb) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psp_version(varchar, smallint) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psp_digest(char) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psp_profile(jsonb, jsonb, jsonb, jsonb, jsonb, varchar, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psh_scope(varchar, varchar, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psh_epoch(bigint) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_pss_tuple(varchar, varchar, uuid, p_proof_sha256 char, char, bigint, varchar, smallint) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_pss_versions(bigint, bigint, bigint, timestamptz, timestamptz) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_pss_state(varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_pss_hold(varchar, varchar, boolean, varchar, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psj_state(varchar, integer, uuid, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psj_counts(integer, bigint, bigint, bigint) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psj_stage(varchar, char, varchar, varchar, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psj_times(timestamptz, timestamptz, timestamptz) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psa_state(varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psa_ordinal(integer, bigint, bigint, bigint) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psa_times(timestamptz, timestamptz, timestamptz, timestamptz) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psa_shape(varchar, timestamptz, timestamptz, varchar, varchar, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psv_shape(varchar, varchar, char, p_observed_sha256 char, char, bigint, varchar, bigint, bigint, bigint, bigint, varchar, timestamptz, timestamptz) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_psv_simulation(varchar, varchar, varchar, varchar, varchar, varchar, varchar) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_pse_digest(char) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_pse_event_shape(varchar, varchar, varchar, varchar, uuid, uuid, uuid, uuid, uuid, uuid, bigint, bigint, bigint, varchar, char, varchar, char, bigint, varchar, timestamptz) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_authority() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_environment(text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_subject_digest(jsonb) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_record_event(text, uuid, uuid, uuid, uuid, uuid, bigint, uuid, timestamp with time zone, text, uuid, text, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_create_policy(jsonb, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_set_head(uuid, uuid, bigint, text, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_new_job(uuid, text, timestamp with time zone, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_initialize_subject(uuid, uuid, uuid, jsonb, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_lock_job(uuid, text, boolean) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_projection(uuid, timestamp with time zone, boolean) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_project(uuid, timestamp with time zone, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_claim_attempt(uuid, bigint, text, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_start_or_expire_attempt(uuid, text, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_accept_result(jsonb, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_rescan_or_dispose(uuid, bigint, text, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_append_audit(uuid, bigint, text, uuid, text, text, jsonb, text) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_guard_row() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_guard_truncate() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_check_subject(uuid) FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_guard_audit() FROM PUBLIC;
        REVOKE ALL ON FUNCTION public.proof_scan_validate_graph() FROM PUBLIC;

        REVOKE ALL ON TABLE public.proof_scan_subjects FROM PUBLIC;
        REVOKE ALL ON TABLE public.proof_scan_policies FROM PUBLIC;
        REVOKE ALL ON TABLE public.proof_scan_policy_heads FROM PUBLIC;
        REVOKE ALL ON TABLE public.proof_scan_jobs FROM PUBLIC;
        REVOKE ALL ON TABLE public.proof_scan_attempts FROM PUBLIC;
        REVOKE ALL ON TABLE public.proof_scan_verdicts FROM PUBLIC;
        REVOKE ALL ON TABLE public.proof_scan_audit_events FROM PUBLIC;
        SQL;
    }
};
