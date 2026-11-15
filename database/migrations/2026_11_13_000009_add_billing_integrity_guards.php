<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['invoices', 'payments', 'financial_ownership_periods', 'invoice_revisions', 'billing_requests', 'billing_actions'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Guard billing memerlukan PostgreSQL.');
        }
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE invoices, payments, financial_ownership_periods, invoice_revisions, billing_requests, billing_actions IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('CREATE INDEX billing_requests_invoice_index ON billing_requests (invoice_id,id)');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION public.billing_guard_fail(rule_name text) RETURNS void
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                BEGIN
                    RAISE EXCEPTION USING ERRCODE = '23514', CONSTRAINT = rule_name, MESSAGE = 'Integritas billing ditolak (' || rule_name || ').';
                END $fn$;

                CREATE FUNCTION public.billing_lock_invoice(root_id uuid) RETURNS public.invoices
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE root public.invoices; resident_id uuid;
                BEGIN
                    IF current_setting('transaction_isolation') <> 'read committed' THEN
                        PERFORM public.billing_guard_fail('billing_isolation_required');
                    END IF;
                    SELECT elderly_id INTO resident_id FROM public.invoices WHERE id = root_id;
                    PERFORM id FROM public.elderlies WHERE id = resident_id FOR UPDATE;
                    SELECT * INTO root FROM public.invoices WHERE id = root_id FOR UPDATE;
                    IF NOT FOUND OR root.billing_schema_version IS DISTINCT FROM 1::smallint THEN
                        PERFORM public.billing_guard_fail('billing_managed_parent_required');
                    END IF;
                    RETURN root;
                END $fn$;

                CREATE FUNCTION public.billing_guard_invoice() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.billing_schema_version = 1 THEN PERFORM public.billing_guard_fail('billing_invoice_history_immutable'); END IF;
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'UPDATE' AND NEW.billing_schema_version IS DISTINCT FROM OLD.billing_schema_version THEN
                        PERFORM public.billing_guard_fail('billing_legacy_version_immutable');
                    END IF;
                    IF NEW.billing_schema_version IS DISTINCT FROM 1::smallint THEN RETURN NEW; END IF;
                    IF current_setting('transaction_isolation') <> 'read committed' THEN
                        PERFORM public.billing_guard_fail('billing_isolation_required');
                    END IF;
                    PERFORM id FROM public.elderlies WHERE id = NEW.elderly_id FOR UPDATE;
                    IF TG_OP = 'INSERT' THEN
                        IF NOT (NEW.status = 'unpaid' OR (NEW.status = 'paid' AND NEW.amount = 0)) THEN
                            PERFORM public.billing_guard_fail('billing_invoice_initial_state');
                        END IF;
                    ELSE
                        IF ROW(NEW.id,NEW.number,NEW.elderly_id,NEW.service_enrollment_id,NEW.billing_month,NEW.currency,
                            NEW.bill_to_family_id,NEW.financial_ownership_period_id,NEW.initial_issued_by,NEW.initial_issued_at,NEW.created_at)
                            IS DISTINCT FROM ROW(OLD.id,OLD.number,OLD.elderly_id,OLD.service_enrollment_id,OLD.billing_month,OLD.currency,
                            OLD.bill_to_family_id,OLD.financial_ownership_period_id,OLD.initial_issued_by,OLD.initial_issued_at,OLD.created_at) THEN
                            PERFORM public.billing_guard_fail('billing_invoice_identity_immutable');
                        END IF;
                        IF OLD.status IN ('paid','cancelled') AND NEW IS DISTINCT FROM OLD THEN
                            PERFORM public.billing_guard_fail('billing_invoice_terminal_immutable');
                        END IF;
                    END IF;
                    RETURN NEW;
                END $fn$;

                CREATE FUNCTION public.billing_guard_revision() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE root public.invoices; tip public.invoice_revisions;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN PERFORM public.billing_guard_fail('billing_revision_immutable'); END IF;
                    root := public.billing_lock_invoice(NEW.invoice_id);
                    SELECT * INTO tip FROM public.invoice_revisions WHERE invoice_id = root.id ORDER BY revision_no DESC LIMIT 1;
                    IF tip.id IS NULL THEN
                        IF NEW.revision_no <> 1 OR NEW.previous_revision_id IS NOT NULL OR root.current_revision_id IS DISTINCT FROM NEW.id THEN
                            PERFORM public.billing_guard_fail('billing_revision_chain');
                        END IF;
                        IF root.status <> 'unpaid' AND NOT (root.status = 'paid' AND root.amount = 0 AND NEW.amount = 0) THEN
                            PERFORM public.billing_guard_fail('billing_revision_parent_state');
                        END IF;
                    ELSE
                        IF root.status <> 'unpaid' THEN PERFORM public.billing_guard_fail('billing_revision_parent_state'); END IF;
                        IF NEW.revision_no::bigint <> tip.revision_no::bigint + 1 OR NEW.previous_revision_id IS DISTINCT FROM tip.id
                            OR root.current_revision_id IS DISTINCT FROM tip.id THEN
                            PERFORM public.billing_guard_fail('billing_revision_chain');
                        END IF;
                        IF NEW.amount = tip.amount THEN PERFORM public.billing_guard_fail('billing_revision_material_change'); END IF;
                        IF NEW.issued_at <= tip.issued_at THEN PERFORM public.billing_guard_fail('billing_revision_time_order'); END IF;
                    END IF;
                    IF EXISTS (SELECT 1 FROM public.payments WHERE invoice_id = root.id AND status IN ('pending','verified')) THEN
                        PERFORM public.billing_guard_fail('billing_revision_payment_conflict');
                    END IF;
                    RETURN NEW;
                END $fn$;

                CREATE FUNCTION public.billing_guard_payment() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE root public.invoices;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.billing_schema_version = 1 THEN PERFORM public.billing_guard_fail('billing_payment_history_immutable'); END IF;
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'UPDATE' AND NEW.billing_schema_version IS DISTINCT FROM OLD.billing_schema_version THEN
                        PERFORM public.billing_guard_fail('billing_legacy_version_immutable');
                    END IF;
                    IF NEW.billing_schema_version IS DISTINCT FROM 1::smallint THEN
                        IF EXISTS (SELECT 1 FROM public.invoices WHERE id = NEW.invoice_id AND billing_schema_version = 1) THEN
                            PERFORM public.billing_guard_fail('billing_managed_payment_required');
                        END IF;
                        RETURN NEW;
                    END IF;
                    root := public.billing_lock_invoice(NEW.invoice_id);
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.status <> 'pending' THEN PERFORM public.billing_guard_fail('billing_payment_initial_state'); END IF;
                    ELSE
                        IF OLD.status IN ('verified','rejected') AND NEW IS DISTINCT FROM OLD THEN
                            PERFORM public.billing_guard_fail('billing_payment_terminal_immutable');
                        END IF;
                        IF (to_jsonb(NEW) - ARRAY['status','verified_by','verified_at','verification_notes','public_rejection_reason','updated_at'])
                            IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','verified_by','verified_at','verification_notes','public_rejection_reason','updated_at']) THEN
                            PERFORM public.billing_guard_fail('billing_payment_submission_immutable');
                        END IF;
                        IF OLD.status = 'pending' AND NEW.status NOT IN ('pending','verified','rejected') THEN
                            PERFORM public.billing_guard_fail('billing_payment_transition');
                        END IF;
                    END IF;
                    IF TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND OLD.status = 'pending') THEN
                        IF root.status <> 'unpaid' OR root.amount <= 0 OR NEW.invoice_revision_id IS DISTINCT FROM root.current_revision_id
                            OR NEW.amount IS DISTINCT FROM root.amount THEN
                            PERFORM public.billing_guard_fail('billing_payment_current_amount');
                        END IF;
                    END IF;
                    IF NEW.status <> 'rejected' AND NEW.public_rejection_reason IS NOT NULL THEN
                        PERFORM public.billing_guard_fail('billing_payment_public_reason');
                    END IF;
                    RETURN NEW;
                END $fn$;

                CREATE FUNCTION public.billing_guard_period() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE previous public.financial_ownership_periods;
                BEGIN
                    IF TG_OP = 'DELETE' THEN PERFORM public.billing_guard_fail('billing_ownership_history_immutable'); END IF;
                    IF current_setting('transaction_isolation') <> 'read committed' THEN PERFORM public.billing_guard_fail('billing_isolation_required'); END IF;
                    PERFORM id FROM public.elderlies WHERE id = NEW.elderly_id FOR UPDATE;
                    IF TG_OP = 'UPDATE' THEN
                        IF OLD.effective_to IS NOT NULL OR NEW.effective_to IS NULL
                            OR (to_jsonb(NEW) - 'effective_to') IS DISTINCT FROM (to_jsonb(OLD) - 'effective_to') THEN
                            PERFORM public.billing_guard_fail('billing_ownership_close_only');
                        END IF;
                    ELSE
                        IF NEW.effective_to IS NOT NULL OR NEW.effective_from IS DISTINCT FROM NEW.recorded_at THEN
                            PERFORM public.billing_guard_fail('billing_ownership_prospective_shape');
                        END IF;
                        IF NEW.previous_period_id IS NOT NULL THEN
                            SELECT * INTO previous FROM public.financial_ownership_periods WHERE id = NEW.previous_period_id AND elderly_id = NEW.elderly_id;
                            IF previous.id IS NULL OR previous.effective_to IS DISTINCT FROM NEW.effective_from
                                OR previous.family_id = NEW.family_id THEN
                                PERFORM public.billing_guard_fail('billing_ownership_successor');
                            END IF;
                        END IF;
                    END IF;
                    RETURN NEW;
                END $fn$;

                CREATE FUNCTION public.billing_guard_request() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE root public.invoices; prior_hash char(64);
                BEGIN
                    IF TG_OP <> 'INSERT' THEN PERFORM public.billing_guard_fail('billing_request_immutable'); END IF;
                    root := public.billing_lock_invoice(NEW.invoice_id);
                    SELECT intent_hash INTO prior_hash FROM public.billing_requests WHERE actor_id = NEW.actor_id AND request_id = NEW.request_id;
                    IF FOUND AND prior_hash IS DISTINCT FROM NEW.intent_hash THEN
                        PERFORM public.billing_guard_fail('billing_request_intent_conflict');
                    END IF;
                    RETURN NEW;
                END $fn$;

                CREATE FUNCTION public.billing_guard_action() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE root public.invoices; next_sequence bigint; command public.billing_requests; period public.financial_ownership_periods;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN PERFORM public.billing_guard_fail('billing_action_immutable'); END IF;
                    IF NEW.invoice_id IS NOT NULL THEN
                        root := public.billing_lock_invoice(NEW.invoice_id);
                        SELECT COALESCE(max(invoice_sequence),0) + 1 INTO next_sequence FROM public.billing_actions WHERE invoice_id = root.id;
                        IF NEW.invoice_sequence IS DISTINCT FROM next_sequence THEN PERFORM public.billing_guard_fail('billing_action_sequence'); END IF;
                        SELECT * INTO command FROM public.billing_requests WHERE id = NEW.billing_request_id;
                        IF command.invoice_id IS DISTINCT FROM root.id OR command.actor_id IS DISTINCT FROM NEW.actor_id
                            OR (NEW.action IN ('invoice_issued','zero_charge') AND command.operation NOT IN ('issue','revise'))
                            OR (NEW.action = 'invoice_revised' AND command.operation <> 'revise')
                            OR (NEW.action = 'invoice_cancelled' AND command.operation <> 'cancel')
                            OR (NEW.action = 'payment_submitted' AND command.operation NOT IN ('submit','assist_submit'))
                            OR (NEW.action = 'payment_verified' AND command.operation <> 'verify')
                            OR (NEW.action = 'payment_rejected' AND command.operation <> 'reject') THEN
                            PERFORM public.billing_guard_fail('billing_action_command_context');
                        END IF;
                        IF NEW.action IN ('invoice_issued','invoice_revised','zero_charge') AND command.outcome_revision_id IS DISTINCT FROM NEW.revision_id
                            OR NEW.action IN ('payment_submitted','payment_verified','payment_rejected') AND command.outcome_payment_id IS DISTINCT FROM NEW.payment_id
                            OR command.outcome_revision_id IS NOT NULL AND command.outcome_revision_id IS DISTINCT FROM NEW.revision_id THEN
                            PERFORM public.billing_guard_fail('billing_action_outcome');
                        END IF;
                    ELSE
                        IF current_setting('transaction_isolation') <> 'read committed' THEN PERFORM public.billing_guard_fail('billing_isolation_required'); END IF;
                        SELECT * INTO period FROM public.financial_ownership_periods WHERE id = NEW.ownership_period_id;
                        PERFORM id FROM public.elderlies WHERE id = period.elderly_id FOR UPDATE;
                        IF (NEW.action = 'ownership_captured' AND period.source <> 'cutover_capture')
                            OR (NEW.action = 'ownership_opened' AND period.source NOT IN ('resident_create','family_transfer'))
                            OR (NEW.action = 'ownership_closed' AND period.effective_to IS NULL) THEN
                            PERFORM public.billing_guard_fail('billing_ownership_action_context');
                        END IF;
                    END IF;
                    RETURN NEW;
                END $fn$;

                CREATE FUNCTION public.billing_validate_ownership(resident_id uuid) RETURNS void
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE leaf_family uuid;
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM public.financial_ownership_periods WHERE elderly_id = resident_id) THEN RETURN; END IF;
                    IF (SELECT count(*) FROM public.financial_ownership_periods WHERE elderly_id = resident_id AND previous_period_id IS NULL) <> 1
                        OR (SELECT count(*) FROM public.financial_ownership_periods WHERE elderly_id = resident_id AND effective_to IS NULL) <> 1 THEN
                        PERFORM public.billing_guard_fail('billing_ownership_final_chain');
                    END IF;
                    IF EXISTS (SELECT 1 FROM (
                        SELECT p.*, lag(id) OVER w AS expected_previous, lag(effective_to) OVER w AS previous_end,
                            lag(family_id) OVER w AS previous_family, row_number() OVER w AS position
                        FROM public.financial_ownership_periods p WHERE elderly_id = resident_id
                        WINDOW w AS (ORDER BY effective_from,id)
                    ) chain WHERE effective_from IS DISTINCT FROM recorded_at
                        OR (position = 1 AND previous_period_id IS NOT NULL)
                        OR (position > 1 AND (previous_period_id IS DISTINCT FROM expected_previous OR effective_from IS DISTINCT FROM previous_end OR family_id = previous_family))) THEN
                        PERFORM public.billing_guard_fail('billing_ownership_final_chain');
                    END IF;
                    SELECT family_id INTO leaf_family FROM public.financial_ownership_periods WHERE elderly_id = resident_id AND effective_to IS NULL;
                    IF leaf_family IS DISTINCT FROM (SELECT family_id FROM public.elderlies WHERE id = resident_id) THEN
                        PERFORM public.billing_guard_fail('billing_ownership_final_family');
                    END IF;
                    IF EXISTS (SELECT 1 FROM public.financial_ownership_periods p WHERE p.elderly_id = resident_id AND (
                        (SELECT count(*) FROM public.billing_actions a WHERE a.ownership_period_id = p.id AND a.action = CASE WHEN p.source = 'cutover_capture' THEN 'ownership_captured' ELSE 'ownership_opened' END
                            AND a.actor_id = p.recorded_by AND a.occurred_at = p.recorded_at) <> 1
                        OR (SELECT count(*) FROM public.billing_actions a WHERE a.ownership_period_id = p.id AND a.action = 'ownership_closed'
                            AND a.occurred_at = p.effective_to) <> CASE WHEN p.effective_to IS NULL THEN 0 ELSE 1 END
                        OR (SELECT count(*) FROM public.billing_actions a WHERE a.ownership_period_id = p.id) <> CASE WHEN p.effective_to IS NULL THEN 1 ELSE 2 END
                        OR (p.previous_period_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.billing_actions a WHERE a.ownership_period_id = p.previous_period_id
                            AND a.action = 'ownership_closed' AND a.actor_id = p.recorded_by AND a.occurred_at = p.effective_from))
                    )) THEN PERFORM public.billing_guard_fail('billing_ownership_audit'); END IF;
                    IF EXISTS (SELECT 1 FROM public.invoices i JOIN public.invoice_revisions r ON r.invoice_id = i.id
                        JOIN public.financial_ownership_periods p ON p.id = i.financial_ownership_period_id
                        WHERE i.billing_schema_version = 1 AND i.elderly_id = resident_id AND p.elderly_id = resident_id
                        AND (r.effective_started_at < p.effective_from OR (p.effective_to IS NOT NULL AND r.effective_ended_at > p.effective_to))) THEN
                        PERFORM public.billing_guard_fail('billing_ownership_billed_coverage');
                    END IF;
                END $fn$;

                CREATE FUNCTION public.billing_validate_invoice(root_id uuid) RETURNS void
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE root public.invoices; current_revision public.invoice_revisions; period public.financial_ownership_periods;
                    first_revision public.invoice_revisions; revision_count bigint; tip_number integer; verified_count bigint; pending_count bigint;
                    action_count bigint; action_max bigint; action_min bigint;
                BEGIN
                    SELECT * INTO root FROM public.invoices WHERE id = root_id;
                    IF NOT FOUND OR root.billing_schema_version IS DISTINCT FROM 1::smallint THEN RETURN; END IF;
                    SELECT * INTO current_revision FROM public.invoice_revisions WHERE invoice_id = root.id AND id = root.current_revision_id;
                    SELECT count(*),max(revision_no) INTO revision_count,tip_number FROM public.invoice_revisions WHERE invoice_id = root.id;
                    IF current_revision.id IS NULL OR revision_count <> tip_number OR current_revision.revision_no <> tip_number
                        OR EXISTS (SELECT 1 FROM public.invoice_revisions r LEFT JOIN public.invoice_revisions previous
                            ON previous.invoice_id = r.invoice_id AND previous.id = r.previous_revision_id
                            WHERE r.invoice_id = root.id AND ((r.revision_no = 1 AND r.previous_revision_id IS NOT NULL)
                                OR (r.revision_no > 1 AND (previous.id IS NULL OR previous.revision_no <> r.revision_no - 1
                                    OR previous.amount = r.amount OR previous.issued_at >= r.issued_at)))) THEN
                        PERFORM public.billing_guard_fail('billing_invoice_revision_graph');
                    END IF;
                    SELECT * INTO first_revision FROM public.invoice_revisions WHERE invoice_id = root.id AND revision_no = 1;
                    IF first_revision.id IS NULL OR first_revision.issued_by IS DISTINCT FROM root.initial_issued_by
                        OR first_revision.issued_at IS DISTINCT FROM root.initial_issued_at THEN
                        PERFORM public.billing_guard_fail('billing_invoice_initial_issue');
                    END IF;
                    IF ROW(root.amount,root.currency,root.due_date,root.grace_period_ends_on)
                        IS DISTINCT FROM ROW(current_revision.amount,current_revision.currency,current_revision.due_date,current_revision.grace_period_ends_on) THEN
                        PERFORM public.billing_guard_fail('billing_invoice_projection');
                    END IF;
                    SELECT * INTO period FROM public.financial_ownership_periods WHERE id = root.financial_ownership_period_id;
                    IF period.id IS NULL OR period.elderly_id IS DISTINCT FROM root.elderly_id OR period.family_id IS DISTINCT FROM root.bill_to_family_id THEN
                        PERFORM public.billing_guard_fail('billing_invoice_owner_witness');
                    END IF;
                    IF EXISTS (SELECT 1 FROM public.invoice_revisions r JOIN public.service_enrollments e ON e.id = root.service_enrollment_id
                        WHERE r.invoice_id = root.id AND (r.elderly_id IS DISTINCT FROM root.elderly_id OR r.service_enrollment_id IS DISTINCT FROM root.service_enrollment_id
                            OR r.effective_started_at < period.effective_from OR (period.effective_to IS NOT NULL AND r.effective_ended_at > period.effective_to)
                            OR r.effective_started_at < e.started_at OR (e.ended_at IS NOT NULL AND r.effective_ended_at > e.ended_at)
                            OR r.effective_started_at < root.billing_month::timestamp AT TIME ZONE 'Asia/Jakarta'
                            OR r.effective_ended_at > (root.billing_month + interval '1 month')::timestamp AT TIME ZONE 'Asia/Jakarta'
                            OR r.issued_at < (root.billing_month + interval '1 month')::timestamp AT TIME ZONE 'Asia/Jakarta')) THEN
                        PERFORM public.billing_guard_fail('billing_invoice_charge_coverage');
                    END IF;
                    IF (SELECT count(DISTINCT family_id) FROM public.financial_ownership_periods WHERE elderly_id = root.elderly_id
                        AND effective_from < (root.billing_month + interval '1 month')::timestamp AT TIME ZONE 'Asia/Jakarta'
                        AND (effective_to IS NULL OR effective_to > root.billing_month::timestamp AT TIME ZONE 'Asia/Jakarta')) > 1 THEN
                        PERFORM public.billing_guard_fail('billing_invoice_mixed_owner_month');
                    END IF;
                    SELECT count(*) FILTER (WHERE status = 'verified'),count(*) FILTER (WHERE status = 'pending')
                        INTO verified_count,pending_count FROM public.payments WHERE invoice_id = root.id;
                    IF EXISTS (SELECT 1 FROM public.payments p WHERE p.invoice_id = root.id AND (p.billing_schema_version IS DISTINCT FROM 1::smallint
                        OR (p.status IN ('pending','verified') AND (p.invoice_revision_id IS DISTINCT FROM current_revision.id OR p.amount IS DISTINCT FROM current_revision.amount OR p.amount <= 0))
                        OR (p.status = 'pending' AND root.status <> 'unpaid')
                        OR (p.status = 'verified' AND (root.status <> 'paid' OR root.paid_at IS DISTINCT FROM p.verified_at))
                        OR (current_revision.amount = 0 AND p.invoice_revision_id = current_revision.id)))
                        OR (root.status = 'paid' AND root.amount > 0 AND verified_count <> 1)
                        OR (root.status <> 'paid' AND verified_count <> 0)
                        OR (root.status = 'paid' AND root.amount = 0 AND (pending_count <> 0 OR verified_count <> 0))
                        OR (root.status = 'cancelled' AND pending_count <> 0)
                        OR (root.amount = 0 AND root.status <> 'paid') THEN
                        PERFORM public.billing_guard_fail('billing_invoice_payment_state');
                    END IF;
                    SELECT count(*),COALESCE(max(invoice_sequence),0),COALESCE(min(invoice_sequence),0)
                        INTO action_count,action_max,action_min FROM public.billing_actions WHERE invoice_id = root.id;
                    IF action_count = 0 OR action_min <> 1 OR action_count <> action_max THEN PERFORM public.billing_guard_fail('billing_invoice_audit_sequence'); END IF;
                    IF EXISTS (SELECT 1 FROM public.invoice_revisions r WHERE r.invoice_id = root.id AND (
                        (SELECT count(*) FROM public.billing_actions a JOIN public.billing_requests q ON q.id = a.billing_request_id
                            WHERE a.invoice_id = root.id AND a.revision_id = r.id AND a.action = CASE WHEN r.revision_no = 1 THEN 'invoice_issued' ELSE 'invoice_revised' END
                            AND a.actor_id = r.issued_by AND a.occurred_at >= r.issued_at
                            AND q.operation = CASE WHEN r.revision_no = 1 THEN 'issue' ELSE 'revise' END AND q.outcome_revision_id = r.id) <> 1
                        OR (r.amount = 0 AND (SELECT count(*) FROM public.billing_actions a WHERE a.invoice_id = root.id AND a.revision_id = r.id
                            AND a.action = 'zero_charge' AND a.actor_id = r.issued_by) <> 1)
                    )) THEN PERFORM public.billing_guard_fail('billing_invoice_revision_audit'); END IF;
                    IF EXISTS (SELECT 1 FROM public.payments p WHERE p.invoice_id = root.id AND (
                        (SELECT count(*) FROM public.billing_actions a JOIN public.billing_requests q ON q.id = a.billing_request_id
                            WHERE a.payment_id = p.id AND a.invoice_id = root.id AND a.revision_id = p.invoice_revision_id AND a.action = 'payment_submitted'
                                AND a.actor_id = p.submitted_by AND q.outcome_payment_id = p.id
                                AND q.operation = CASE WHEN p.submission_channel = 'admin_assisted' THEN 'assist_submit' ELSE 'submit' END) <> 1
                        OR (p.status IN ('verified','rejected') AND (SELECT count(*) FROM public.billing_actions a JOIN public.billing_requests q ON q.id = a.billing_request_id
                            WHERE a.payment_id = p.id AND a.invoice_id = root.id AND a.revision_id = p.invoice_revision_id
                                AND a.action = CASE WHEN p.status = 'verified' THEN 'payment_verified' ELSE 'payment_rejected' END
                                AND a.actor_id = p.verified_by AND q.outcome_payment_id = p.id
                                AND q.operation = CASE WHEN p.status = 'verified' THEN 'verify' ELSE 'reject' END
                                AND date_trunc('second',a.occurred_at) = p.verified_at) <> 1)
                    )) THEN PERFORM public.billing_guard_fail('billing_invoice_payment_audit'); END IF;
                    IF EXISTS (SELECT 1 FROM public.billing_actions a WHERE a.invoice_id = root.id AND (
                        (a.action = 'invoice_issued' AND NOT EXISTS (SELECT 1 FROM public.invoice_revisions r WHERE r.id = a.revision_id AND r.revision_no = 1 AND r.issued_by = a.actor_id AND a.occurred_at >= r.issued_at))
                        OR (a.action = 'invoice_revised' AND NOT EXISTS (SELECT 1 FROM public.invoice_revisions r WHERE r.id = a.revision_id AND r.revision_no > 1 AND r.issued_by = a.actor_id AND a.occurred_at >= r.issued_at))
                        OR (a.action = 'zero_charge' AND NOT EXISTS (SELECT 1 FROM public.invoice_revisions r WHERE r.id = a.revision_id AND r.amount = 0 AND r.issued_by = a.actor_id AND a.occurred_at >= r.issued_at))
                        OR (a.action = 'payment_submitted' AND NOT EXISTS (SELECT 1 FROM public.payments p WHERE p.id = a.payment_id AND p.submitted_by = a.actor_id AND p.invoice_revision_id = a.revision_id))
                        OR (a.action = 'payment_verified' AND NOT EXISTS (SELECT 1 FROM public.payments p WHERE p.id = a.payment_id AND p.status = 'verified' AND p.invoice_revision_id = a.revision_id
                            AND p.verified_by = a.actor_id AND date_trunc('second',a.occurred_at) = p.verified_at))
                        OR (a.action = 'payment_rejected' AND NOT EXISTS (SELECT 1 FROM public.payments p WHERE p.id = a.payment_id AND p.status = 'rejected' AND p.invoice_revision_id = a.revision_id
                            AND p.verified_by = a.actor_id AND date_trunc('second',a.occurred_at) = p.verified_at))
                        OR (a.action = 'invoice_cancelled' AND (root.status <> 'cancelled' OR a.actor_id IS DISTINCT FROM root.cancelled_by
                            OR a.revision_id IS DISTINCT FROM root.current_revision_id OR a.occurred_at IS DISTINCT FROM root.cancelled_at))
                    )) THEN PERFORM public.billing_guard_fail('billing_invoice_action_state'); END IF;
                    IF root.status = 'cancelled' AND (SELECT count(*) FROM public.billing_actions a WHERE a.invoice_id = root.id
                        AND a.action = 'invoice_cancelled' AND a.revision_id = root.current_revision_id AND a.actor_id = root.cancelled_by AND a.occurred_at = root.cancelled_at) <> 1 THEN
                        PERFORM public.billing_guard_fail('billing_invoice_cancellation_audit');
                    END IF;
                    IF EXISTS (SELECT 1 FROM public.billing_requests q WHERE q.invoice_id = root.id AND NOT EXISTS (
                        SELECT 1 FROM public.billing_actions a WHERE a.billing_request_id = q.id AND a.invoice_id = q.invoice_id AND a.actor_id = q.actor_id
                        AND ((q.operation = 'issue' AND a.action = 'invoice_issued') OR (q.operation = 'revise' AND a.action = 'invoice_revised')
                            OR (q.operation = 'cancel' AND a.action = 'invoice_cancelled') OR (q.operation IN ('submit','assist_submit') AND a.action = 'payment_submitted')
                            OR (q.operation = 'verify' AND a.action = 'payment_verified') OR (q.operation = 'reject' AND a.action = 'payment_rejected'))
                    )) THEN PERFORM public.billing_guard_fail('billing_invoice_request_audit'); END IF;
                END $fn$;

                CREATE FUNCTION public.billing_deferred_check() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                DECLARE resident_id uuid;
                BEGIN
                    IF TG_TABLE_NAME = 'financial_ownership_periods' THEN
                        PERFORM public.billing_validate_ownership(NEW.elderly_id);
                    ELSIF TG_TABLE_NAME = 'billing_actions' THEN
                        IF NEW.ownership_period_id IS NOT NULL THEN
                            SELECT elderly_id INTO resident_id FROM public.financial_ownership_periods WHERE id = NEW.ownership_period_id;
                            PERFORM public.billing_validate_ownership(resident_id);
                        ELSE
                            PERFORM public.billing_validate_invoice(NEW.invoice_id);
                        END IF;
                    ELSIF TG_TABLE_NAME = 'invoices' THEN
                        PERFORM public.billing_validate_invoice(NEW.id);
                    ELSE
                        PERFORM public.billing_validate_invoice(NEW.invoice_id);
                    END IF;
                    RETURN NULL;
                END $fn$;

                CREATE FUNCTION public.billing_guard_truncate() RETURNS trigger
                LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
                BEGIN
                    PERFORM public.billing_guard_fail('billing_history_truncate_forbidden');
                    RETURN NULL;
                END $fn$;
                SQL);

            // Preflight membaca final graph existing; tidak melakukan backfill/repair.
            DB::statement(<<<'SQL'
                DO $preflight$
                DECLARE row record;
                BEGIN
                    IF EXISTS (SELECT 1 FROM invoice_revisions r JOIN invoices i ON i.id=r.invoice_id WHERE i.billing_schema_version IS DISTINCT FROM 1::smallint)
                        OR EXISTS (SELECT 1 FROM billing_requests q JOIN invoices i ON i.id=q.invoice_id WHERE i.billing_schema_version IS DISTINCT FROM 1::smallint) THEN
                        PERFORM public.billing_guard_fail('billing_preflight_legacy_children');
                    END IF;
                    FOR row IN SELECT DISTINCT elderly_id FROM financial_ownership_periods LOOP
                        PERFORM public.billing_validate_ownership(row.elderly_id);
                    END LOOP;
                    FOR row IN SELECT id FROM invoices WHERE billing_schema_version = 1 LOOP
                        PERFORM public.billing_validate_invoice(row.id);
                    END LOOP;
                END $preflight$
                SQL);
            foreach (['invoices' => 'invoice', 'payments' => 'payment', 'financial_ownership_periods' => 'period',
                'invoice_revisions' => 'revision', 'billing_requests' => 'request', 'billing_actions' => 'action'] as $table => $function) {
                DB::statement("CREATE TRIGGER billing_{$function}_write BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION public.billing_guard_{$function}()");
                $when = in_array($table, ['invoices', 'payments'], true) ? 'WHEN (NEW.billing_schema_version = 1)' : '';
                DB::statement("CREATE CONSTRAINT TRIGGER billing_{$function}_final AFTER INSERT OR UPDATE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW {$when} EXECUTE FUNCTION public.billing_deferred_check()");
                DB::statement("CREATE TRIGGER billing_{$function}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION public.billing_guard_truncate()");
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Guard billing memerlukan PostgreSQL.');
        }
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE invoices, payments, financial_ownership_periods, invoice_revisions, billing_requests, billing_actions IN ACCESS EXCLUSIVE MODE');
            foreach (['financial_ownership_periods', 'invoice_revisions', 'billing_requests', 'billing_actions'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Rollback guard billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.');
                }
            }
            foreach (['invoices' => ['billing_schema_version', 'bill_to_family_id', 'financial_ownership_period_id', 'initial_issued_by', 'initial_issued_at', 'current_revision_id', 'cancelled_by', 'cancelled_at', 'cancellation_reason'],
                'payments' => ['billing_schema_version', 'invoice_revision_id', 'submission_channel', 'proof_sha256', 'proof_size_bytes', 'proof_mime', 'proof_disk', 'public_rejection_reason']] as $table => $columns) {
                if (DB::table($table)->where(function ($query) use ($columns) {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                })->exists()) {
                    throw new RuntimeException('Rollback guard billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.');
                }
            }
            foreach (['invoices' => 'invoice', 'payments' => 'payment', 'financial_ownership_periods' => 'period',
                'invoice_revisions' => 'revision', 'billing_requests' => 'request', 'billing_actions' => 'action'] as $table => $function) {
                foreach (['final', 'write', 'truncate'] as $suffix) {
                    DB::statement("DROP TRIGGER billing_{$function}_{$suffix} ON {$table}");
                }
            }
            DB::statement('DROP INDEX billing_requests_invoice_index');
            foreach (['deferred_check()', 'guard_truncate()', 'guard_action()', 'guard_request()', 'guard_period()',
                'guard_payment()', 'guard_revision()', 'guard_invoice()', 'validate_invoice(uuid)', 'validate_ownership(uuid)', 'lock_invoice(uuid)', 'guard_fail(text)'] as $function) {
                DB::statement("DROP FUNCTION public.billing_{$function}");
            }
        });
    }
};
