<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INVOICE_COLUMNS = [
        'billing_schema_version', 'bill_to_family_id', 'financial_ownership_period_id',
        'initial_issued_by', 'initial_issued_at', 'current_revision_id',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    private const PAYMENT_COLUMNS = [
        'billing_schema_version', 'invoice_revision_id', 'submission_channel', 'proof_sha256',
        'proof_size_bytes', 'proof_mime', 'proof_disk', 'public_rejection_reason',
    ];

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Perluasan schema billing memerlukan PostgreSQL.');
        }
    }

    public function up(): void
    {
        $this->postgres();
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE invoices, payments IN SHARE ROW EXCLUSIVE MODE');
            if (DB::selectOne("SELECT 1 FROM payments WHERE status IN ('pending','verified')
                GROUP BY invoice_id,status HAVING count(*) > 1 LIMIT 1")) {
                throw new RuntimeException('Perluasan billing ditolak: terdapat payment pending/verified duplikat per invoice. Data tidak diubah.');
            }

            DB::statement(<<<'SQL'
                CREATE TABLE financial_ownership_periods (
                    id uuid NOT NULL,
                    elderly_id uuid NOT NULL,
                    family_id uuid NOT NULL,
                    previous_period_id uuid,
                    effective_from timestamptz(6) NOT NULL,
                    effective_to timestamptz(6),
                    recorded_by uuid NOT NULL,
                    recorded_at timestamptz(6) NOT NULL,
                    source varchar(24) NOT NULL,
                    CONSTRAINT financial_ownership_periods_pkey PRIMARY KEY (id),
                    CONSTRAINT billing_ownership_context_unique UNIQUE (id,elderly_id,family_id),
                    CONSTRAINT billing_ownership_elderly_context_unique UNIQUE (elderly_id,id),
                    CONSTRAINT billing_ownership_successor_unique UNIQUE (previous_period_id),
                    CONSTRAINT billing_ownership_elderly_foreign FOREIGN KEY (elderly_id)
                        REFERENCES elderlies(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_ownership_family_foreign FOREIGN KEY (family_id)
                        REFERENCES users(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_ownership_actor_foreign FOREIGN KEY (recorded_by)
                        REFERENCES users(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_ownership_previous_foreign FOREIGN KEY (elderly_id,previous_period_id)
                        REFERENCES financial_ownership_periods(elderly_id,id) ON DELETE RESTRICT,
                    CONSTRAINT billing_ownership_period_check CHECK (effective_to IS NULL OR effective_to > effective_from),
                    CONSTRAINT billing_ownership_source_check
                        CHECK (source IN ('cutover_capture','resident_create','family_transfer')),
                    CONSTRAINT billing_ownership_previous_check CHECK (
                        source NOT IN ('cutover_capture','resident_create','family_transfer')
                        OR (source IN ('cutover_capture','resident_create') AND previous_period_id IS NULL)
                        OR (source = 'family_transfer' AND previous_period_id IS NOT NULL)
                    )
                )
                SQL);
            DB::statement('CREATE UNIQUE INDEX billing_ownership_root_unique ON financial_ownership_periods (elderly_id) WHERE previous_period_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX billing_ownership_open_unique ON financial_ownership_periods (elderly_id) WHERE effective_to IS NULL');
            DB::statement('CREATE INDEX billing_ownership_history_index ON financial_ownership_periods (elderly_id,effective_from,id)');

            DB::statement(<<<'SQL'
                ALTER TABLE invoices
                    ADD COLUMN billing_schema_version smallint,
                    ADD COLUMN bill_to_family_id uuid,
                    ADD COLUMN financial_ownership_period_id uuid,
                    ADD COLUMN initial_issued_by uuid,
                    ADD COLUMN initial_issued_at timestamptz(6),
                    ADD COLUMN current_revision_id uuid,
                    ADD COLUMN cancelled_by uuid,
                    ADD COLUMN cancelled_at timestamptz(6),
                    ADD COLUMN cancellation_reason text,
                    ADD CONSTRAINT invoices_billing_context_unique UNIQUE (id,elderly_id,service_enrollment_id),
                    ADD CONSTRAINT invoices_bill_to_family_foreign FOREIGN KEY (bill_to_family_id)
                        REFERENCES users(id) ON DELETE RESTRICT,
                    ADD CONSTRAINT invoices_initial_issuer_foreign FOREIGN KEY (initial_issued_by)
                        REFERENCES users(id) ON DELETE RESTRICT,
                    ADD CONSTRAINT invoices_canceller_foreign FOREIGN KEY (cancelled_by)
                        REFERENCES users(id) ON DELETE RESTRICT,
                    ADD CONSTRAINT invoices_billing_owner_foreign
                        FOREIGN KEY (financial_ownership_period_id,elderly_id,bill_to_family_id)
                        REFERENCES financial_ownership_periods(id,elderly_id,family_id) ON DELETE RESTRICT,
                    ADD CONSTRAINT invoices_billing_version_check
                        CHECK (billing_schema_version IS NULL OR billing_schema_version = 1),
                    ADD CONSTRAINT invoices_billing_fields_check CHECK (
                        billing_schema_version IS NULL OR (
                            bill_to_family_id IS NOT NULL AND financial_ownership_period_id IS NOT NULL
                            AND initial_issued_by IS NOT NULL AND initial_issued_at IS NOT NULL
                            AND current_revision_id IS NOT NULL
                        )
                    ),
                    ADD CONSTRAINT invoices_cancellation_check CHECK (
                        billing_schema_version IS NULL OR (
                            (status = 'cancelled' AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL
                                AND cancellation_reason IS NOT NULL AND char_length(btrim(cancellation_reason)) BETWEEN 1 AND 2000)
                            OR (status <> 'cancelled' AND cancelled_by IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                        )
                    )
                SQL);
            DB::statement('CREATE INDEX invoices_billing_family_month_index ON invoices (bill_to_family_id,billing_month,id)');

            DB::statement(<<<'SQL'
                CREATE TABLE invoice_revisions (
                    id uuid NOT NULL,
                    invoice_id uuid NOT NULL,
                    elderly_id uuid NOT NULL,
                    service_enrollment_id uuid NOT NULL,
                    revision_no integer NOT NULL,
                    previous_revision_id uuid,
                    issued_by uuid NOT NULL,
                    issued_at timestamptz(6) NOT NULL,
                    policy_version varchar(40) NOT NULL,
                    monthly_price numeric(14,2) NOT NULL,
                    effective_started_at timestamptz(6) NOT NULL,
                    effective_ended_at timestamptz(6) NOT NULL,
                    chargeable_days smallint NOT NULL,
                    days_in_month smallint NOT NULL,
                    amount numeric(14,2) NOT NULL,
                    currency varchar(3) NOT NULL,
                    due_date date NOT NULL,
                    grace_period_ends_on date NOT NULL,
                    reason_code varchar(40) NOT NULL,
                    reason text,
                    content_hash char(64) NOT NULL,
                    CONSTRAINT invoice_revisions_pkey PRIMARY KEY (id),
                    CONSTRAINT billing_revisions_context_unique UNIQUE (invoice_id,id),
                    CONSTRAINT billing_revisions_sequence_unique UNIQUE (invoice_id,revision_no),
                    CONSTRAINT billing_revisions_invoice_context_foreign
                        FOREIGN KEY (invoice_id,elderly_id,service_enrollment_id)
                        REFERENCES invoices(id,elderly_id,service_enrollment_id) ON DELETE RESTRICT,
                    CONSTRAINT billing_revisions_previous_foreign FOREIGN KEY (invoice_id,previous_revision_id)
                        REFERENCES invoice_revisions(invoice_id,id) ON DELETE RESTRICT,
                    CONSTRAINT billing_revisions_issuer_foreign FOREIGN KEY (issued_by)
                        REFERENCES users(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_revisions_sequence_check CHECK (revision_no >= 1),
                    CONSTRAINT billing_revisions_previous_check CHECK (
                        (revision_no = 1 AND previous_revision_id IS NULL) OR
                        (revision_no > 1 AND previous_revision_id IS NOT NULL)
                    ),
                    CONSTRAINT billing_revisions_price_check CHECK (monthly_price >= 0 AND monthly_price <> 'NaN'::numeric),
                    CONSTRAINT billing_revisions_amount_check CHECK (amount >= 0 AND amount <> 'NaN'::numeric),
                    CONSTRAINT billing_revisions_currency_check CHECK (currency = 'IDR'),
                    CONSTRAINT billing_revisions_interval_check CHECK (effective_ended_at > effective_started_at),
                    CONSTRAINT billing_revisions_days_check CHECK (
                        days_in_month IN (28,29,30,31) AND chargeable_days BETWEEN 1 AND days_in_month
                    ),
                    CONSTRAINT billing_revisions_due_check CHECK (due_date = (issued_at AT TIME ZONE 'Asia/Jakarta')::date + 7),
                    CONSTRAINT billing_revisions_grace_check CHECK (grace_period_ends_on = due_date + 3),
                    CONSTRAINT billing_revisions_policy_check CHECK (char_length(btrim(policy_version)) BETWEEN 1 AND 40),
                    CONSTRAINT billing_revisions_reason_check CHECK (
                        (revision_no = 1 AND reason_code = 'initial_issue')
                        OR (revision_no > 1 AND reason_code = 'material_correction'
                            AND reason IS NOT NULL AND char_length(btrim(reason)) BETWEEN 1 AND 2000)
                    ),
                    CONSTRAINT billing_revisions_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}$')
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE invoices ADD CONSTRAINT invoices_current_revision_foreign
                    FOREIGN KEY (id,current_revision_id)
                    REFERENCES invoice_revisions(invoice_id,id)
                    ON UPDATE NO ACTION ON DELETE NO ACTION DEFERRABLE INITIALLY DEFERRED
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE payments
                    ADD COLUMN billing_schema_version smallint,
                    ADD COLUMN invoice_revision_id uuid,
                    ADD COLUMN submission_channel varchar(24),
                    ADD COLUMN proof_sha256 char(64),
                    ADD COLUMN proof_size_bytes bigint,
                    ADD COLUMN proof_mime varchar(32),
                    ADD COLUMN proof_disk varchar(32),
                    ADD COLUMN public_rejection_reason text,
                    ADD CONSTRAINT payments_billing_context_unique UNIQUE (invoice_id,id),
                    ADD CONSTRAINT payments_billing_revision_foreign FOREIGN KEY (invoice_id,invoice_revision_id)
                        REFERENCES invoice_revisions(invoice_id,id) ON DELETE RESTRICT,
                    ADD CONSTRAINT payments_billing_version_check CHECK (billing_schema_version IS NULL OR billing_schema_version = 1),
                    ADD CONSTRAINT payments_billing_fields_check CHECK (
                        billing_schema_version IS NULL OR (
                            invoice_revision_id IS NOT NULL AND submission_channel IS NOT NULL
                            AND proof_sha256 IS NOT NULL AND proof_size_bytes IS NOT NULL
                            AND proof_mime IS NOT NULL AND proof_disk IS NOT NULL AND amount > 0
                            AND (status <> 'rejected' OR public_rejection_reason IS NOT NULL)
                        )
                    ),
                    ADD CONSTRAINT payments_billing_channel_check CHECK (
                        submission_channel IS NULL OR submission_channel IN ('family_upload','admin_assisted')
                    ),
                    ADD CONSTRAINT payments_billing_hash_check CHECK (proof_sha256 IS NULL OR proof_sha256 ~ '^[0-9a-f]{64}$'),
                    ADD CONSTRAINT payments_billing_size_check CHECK (proof_size_bytes IS NULL OR proof_size_bytes BETWEEN 1 AND 2097152),
                    ADD CONSTRAINT payments_billing_mime_check CHECK (proof_mime IS NULL OR proof_mime IN ('image/jpeg','image/png')),
                    ADD CONSTRAINT payments_billing_disk_check CHECK (proof_disk IS NULL OR proof_disk = 'local'),
                    ADD CONSTRAINT payments_billing_rejection_check CHECK (
                        public_rejection_reason IS NULL OR char_length(btrim(public_rejection_reason)) BETWEEN 1 AND 2000
                    ),
                    ADD CONSTRAINT payments_billing_reviewer_check CHECK (
                        billing_schema_version IS NULL OR submission_channel <> 'admin_assisted'
                        OR status = 'pending' OR verified_by <> submitted_by
                    )
                SQL);
            DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_invoice ON payments (invoice_id) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX payments_one_verified_per_invoice ON payments (invoice_id) WHERE status = 'verified'");
            DB::statement('CREATE INDEX payments_billing_review_queue_index ON payments (status,submitted_at,id)');

            DB::statement(<<<'SQL'
                CREATE TABLE billing_requests (
                    id uuid NOT NULL,
                    actor_id uuid NOT NULL,
                    request_id uuid NOT NULL,
                    operation varchar(32) NOT NULL,
                    intent_hash char(64) NOT NULL,
                    invoice_id uuid NOT NULL,
                    outcome_payment_id uuid,
                    outcome_revision_id uuid,
                    completed_at timestamptz(6) NOT NULL,
                    CONSTRAINT billing_requests_pkey PRIMARY KEY (id),
                    CONSTRAINT billing_requests_actor_request_unique UNIQUE (actor_id,request_id),
                    CONSTRAINT billing_requests_context_unique UNIQUE (id,invoice_id,actor_id),
                    CONSTRAINT billing_requests_actor_foreign FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_requests_invoice_foreign FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_requests_payment_foreign FOREIGN KEY (invoice_id,outcome_payment_id)
                        REFERENCES payments(invoice_id,id) ON DELETE RESTRICT,
                    CONSTRAINT billing_requests_revision_foreign FOREIGN KEY (invoice_id,outcome_revision_id)
                        REFERENCES invoice_revisions(invoice_id,id) ON DELETE RESTRICT,
                    CONSTRAINT billing_requests_operation_check CHECK (
                        operation IN ('issue','revise','cancel','submit','assist_submit','verify','reject')
                    ),
                    CONSTRAINT billing_requests_hash_check CHECK (intent_hash ~ '^[0-9a-f]{64}$'),
                    CONSTRAINT billing_requests_outcome_check CHECK (
                        operation NOT IN ('issue','revise','cancel','submit','assist_submit','verify','reject')
                        OR (operation IN ('issue','revise') AND outcome_revision_id IS NOT NULL AND outcome_payment_id IS NULL)
                        OR (operation IN ('submit','assist_submit','verify','reject') AND outcome_payment_id IS NOT NULL)
                        OR (operation = 'cancel' AND outcome_payment_id IS NULL)
                    )
                )
                SQL);
            DB::statement(<<<'SQL'
                CREATE TABLE billing_actions (
                    id uuid NOT NULL,
                    billing_request_id uuid,
                    actor_id uuid NOT NULL,
                    invoice_id uuid,
                    ownership_period_id uuid,
                    payment_id uuid,
                    revision_id uuid,
                    invoice_sequence bigint,
                    action varchar(40) NOT NULL,
                    occurred_at timestamptz(6) NOT NULL,
                    reason_code varchar(40),
                    private_note text,
                    details jsonb NOT NULL,
                    CONSTRAINT billing_actions_pkey PRIMARY KEY (id),
                    CONSTRAINT billing_actions_sequence_unique UNIQUE (invoice_id,invoice_sequence),
                    CONSTRAINT billing_actions_actor_foreign FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_actions_invoice_foreign FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_actions_ownership_foreign FOREIGN KEY (ownership_period_id)
                        REFERENCES financial_ownership_periods(id) ON DELETE RESTRICT,
                    CONSTRAINT billing_actions_request_foreign FOREIGN KEY (billing_request_id,invoice_id,actor_id)
                        REFERENCES billing_requests(id,invoice_id,actor_id) ON DELETE RESTRICT,
                    CONSTRAINT billing_actions_payment_foreign FOREIGN KEY (invoice_id,payment_id)
                        REFERENCES payments(invoice_id,id) ON DELETE RESTRICT,
                    CONSTRAINT billing_actions_revision_foreign FOREIGN KEY (invoice_id,revision_id)
                        REFERENCES invoice_revisions(invoice_id,id) ON DELETE RESTRICT,
                    CONSTRAINT billing_actions_action_check CHECK (
                        action IN ('invoice_issued','invoice_revised','invoice_cancelled','zero_charge',
                            'payment_submitted','payment_verified','payment_rejected',
                            'ownership_captured','ownership_opened','ownership_closed')
                    ),
                    CONSTRAINT billing_actions_aggregate_check CHECK (
                        (invoice_id IS NOT NULL AND ownership_period_id IS NULL
                            AND billing_request_id IS NOT NULL AND invoice_sequence IS NOT NULL AND invoice_sequence > 0)
                        OR (invoice_id IS NULL AND ownership_period_id IS NOT NULL
                            AND billing_request_id IS NULL AND invoice_sequence IS NULL
                            AND payment_id IS NULL AND revision_id IS NULL)
                    ),
                    CONSTRAINT billing_actions_shape_check CHECK (
                        action NOT IN ('invoice_issued','invoice_revised','invoice_cancelled','zero_charge',
                            'payment_submitted','payment_verified','payment_rejected',
                            'ownership_captured','ownership_opened','ownership_closed')
                        OR (action IN ('ownership_captured','ownership_opened','ownership_closed') AND ownership_period_id IS NOT NULL)
                        OR (action IN ('invoice_issued','invoice_revised','invoice_cancelled','zero_charge')
                            AND invoice_id IS NOT NULL AND revision_id IS NOT NULL AND payment_id IS NULL)
                        OR (action IN ('payment_submitted','payment_verified','payment_rejected')
                            AND invoice_id IS NOT NULL AND revision_id IS NOT NULL AND payment_id IS NOT NULL)
                    ),
                    CONSTRAINT billing_actions_reason_check CHECK (
                        (reason_code IS NULL OR reason_code ~ '^[a-z][a-z0-9_]{0,39}$')
                        AND (action NOT IN ('invoice_revised','invoice_cancelled','payment_rejected') OR reason_code IS NOT NULL)
                    ),
                    CONSTRAINT billing_actions_note_check CHECK (private_note IS NULL OR char_length(private_note) <= 2000),
                    CONSTRAINT billing_actions_details_check CHECK (
                        jsonb_typeof(details) = 'object'
                        AND jsonb_typeof(details->'v') = 'number'
                        AND details->>'v' = '1' AND jsonb_exists(details,'v')
                        AND octet_length(details::text) <= 8192
                    )
                )
                SQL);
            DB::statement('CREATE INDEX billing_actions_ownership_time_index ON billing_actions (ownership_period_id,occurred_at,id)');
        });
    }

    public function down(): void
    {
        $this->postgres();
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE invoices, payments, financial_ownership_periods, invoice_revisions, billing_requests, billing_actions IN ACCESS EXCLUSIVE MODE');
            foreach (['financial_ownership_periods', 'invoice_revisions', 'billing_requests', 'billing_actions'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Rollback perluasan billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.');
                }
            }
            foreach (['invoices' => self::INVOICE_COLUMNS, 'payments' => self::PAYMENT_COLUMNS] as $table => $columns) {
                if (DB::table($table)->where(function ($query) use ($columns) {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                })->exists()) {
                    throw new RuntimeException('Rollback perluasan billing ditolak: terdapat data atau metadata billing baru. Gunakan perubahan maju.');
                }
            }

            // Urutan eksplisit; tidak memakai DROP CASCADE atau menghapus baris bisnis.
            DB::statement('DROP TABLE billing_actions');
            DB::statement('DROP TABLE billing_requests');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_current_revision_foreign');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_billing_revision_foreign');
            DB::statement('DROP TABLE invoice_revisions');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_billing_owner_foreign');
            DB::statement('DROP TABLE financial_ownership_periods');
            DB::statement('DROP INDEX payments_one_pending_per_invoice');
            DB::statement('DROP INDEX payments_one_verified_per_invoice');
            DB::statement('DROP INDEX payments_billing_review_queue_index');
            DB::statement('DROP INDEX invoices_billing_family_month_index');

            foreach (['invoices' => self::INVOICE_COLUMNS, 'payments' => self::PAYMENT_COLUMNS] as $table => $columns) {
                // DROP COLUMN hanya sesudah semua baris terbukti NULL; local CHECK/FK ikut kolomnya.
                foreach ($columns as $column) {
                    DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
                }
            }
            DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_billing_context_unique');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_billing_context_unique');
        });
    }
};
