<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

// Separate technical source pin. This class never learns expected values from a target DB.
final class ProofScanSchemaContract
{
    private const SOURCES = [
        'database/migrations/2026_11_18_000010_create_proof_scan_technical_schema.php' => '6fa2b3ce9de9e8922ca4b9fb3c0ef2321da34cc02b9cfd7e407faf2335e21b43',
        'database/migrations/2026_11_18_000011_add_proof_scan_completeness_guards.php' => '3e5811552ac70106b98a252d15b5cc2faefd1b6117b838191c16a50c37abe3ab',
        'app/Services/Billing/ProofScanReadiness.php' => '53e58c7d639fd55c67be650ac91538aca7ff20821b27442aa8068834ff09f40b',
    ];

    private const CATALOG = <<<'JSON'
    {
        "tables": [
            {
                "name": "proof_scan_subjects",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "payment_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "invoice_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "revision_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "submitter_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "proof_reference",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "billing_request_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "billing_action_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "proof_disk",
                        "type": "character varying(32)",
                        "nullable": false
                    },
                    {
                        "name": "proof_path",
                        "type": "character varying(512)",
                        "nullable": false
                    },
                    {
                        "name": "proof_sha256",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "subject_digest",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "proof_size_bytes",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "proof_mime",
                        "type": "character varying(32)",
                        "nullable": false
                    },
                    {
                        "name": "descriptor_version",
                        "type": "smallint",
                        "nullable": false
                    },
                    {
                        "name": "created_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "changed_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "generation",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "state_version",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "policy_epoch",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "policy_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "admission_incarnation",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "state",
                        "type": "character varying(24)",
                        "nullable": false
                    },
                    {
                        "name": "held_reason",
                        "type": "character varying(48)",
                        "nullable": false
                    },
                    {
                        "name": "security_hold",
                        "type": "boolean",
                        "nullable": false
                    },
                    {
                        "name": "hold_authority_ref",
                        "type": "character varying(256)",
                        "nullable": true
                    },
                    {
                        "name": "hold_reason",
                        "type": "character varying(48)",
                        "nullable": true
                    }
                ]
            },
            {
                "name": "proof_scan_policies",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "version",
                        "type": "character varying(64)",
                        "nullable": false
                    },
                    {
                        "name": "definition_version",
                        "type": "smallint",
                        "nullable": false
                    },
                    {
                        "name": "definition_digest",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "stage_plan",
                        "type": "jsonb",
                        "nullable": false
                    },
                    {
                        "name": "allowed_versions",
                        "type": "jsonb",
                        "nullable": false
                    },
                    {
                        "name": "retry_profile",
                        "type": "jsonb",
                        "nullable": false
                    },
                    {
                        "name": "freshness_profile",
                        "type": "jsonb",
                        "nullable": false
                    },
                    {
                        "name": "resource_profile",
                        "type": "jsonb",
                        "nullable": false
                    },
                    {
                        "name": "approval_reference",
                        "type": "character varying(256)",
                        "nullable": false
                    },
                    {
                        "name": "created_by_function",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "created_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    }
                ]
            },
            {
                "name": "proof_scan_policy_heads",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "scope_key",
                        "type": "character varying(64)",
                        "nullable": false
                    },
                    {
                        "name": "policy_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "admission_incarnation",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "epoch",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "admission",
                        "type": "character varying(16)",
                        "nullable": false
                    },
                    {
                        "name": "authority_ref",
                        "type": "character varying(256)",
                        "nullable": false
                    },
                    {
                        "name": "changed_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    }
                ]
            },
            {
                "name": "proof_scan_jobs",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "subject_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "policy_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "admission_incarnation",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "generation",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "policy_epoch",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "fence",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "stage_id",
                        "type": "character varying(48)",
                        "nullable": false
                    },
                    {
                        "name": "stage_plan_digest",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "state",
                        "type": "character varying(24)",
                        "nullable": false
                    },
                    {
                        "name": "not_before",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "created_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "changed_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "attempt_count",
                        "type": "integer",
                        "nullable": false
                    },
                    {
                        "name": "current_attempt_id",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "terminal_reason",
                        "type": "character varying(48)",
                        "nullable": true
                    },
                    {
                        "name": "origin",
                        "type": "character varying(24)",
                        "nullable": false
                    },
                    {
                        "name": "requested_by_function",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "authority_ref",
                        "type": "character varying(256)",
                        "nullable": false
                    }
                ]
            },
            {
                "name": "proof_scan_attempts",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "job_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "challenge_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "admission_incarnation",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "attempt_no",
                        "type": "integer",
                        "nullable": false
                    },
                    {
                        "name": "generation",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "fence",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "identity_epoch",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "scanner_identity",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "deployment_audience",
                        "type": "character varying(256)",
                        "nullable": false
                    },
                    {
                        "name": "state",
                        "type": "character varying(24)",
                        "nullable": false
                    },
                    {
                        "name": "leased_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "lease_deadline",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "started_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": true
                    },
                    {
                        "name": "completed_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": true
                    },
                    {
                        "name": "failure_code",
                        "type": "character varying(48)",
                        "nullable": true
                    }
                ]
            },
            {
                "name": "proof_scan_verdicts",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "result_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "attempt_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "job_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "subject_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "policy_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "admission_incarnation",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "generation",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "fence",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "policy_epoch",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "identity_epoch",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "observed_size_bytes",
                        "type": "bigint",
                        "nullable": false
                    },
                    {
                        "name": "subject_digest",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "observed_sha256",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "envelope_digest",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "stage_id",
                        "type": "character varying(48)",
                        "nullable": false
                    },
                    {
                        "name": "reason_code",
                        "type": "character varying(48)",
                        "nullable": false
                    },
                    {
                        "name": "observed_mime",
                        "type": "character varying(32)",
                        "nullable": false
                    },
                    {
                        "name": "verdict",
                        "type": "character varying(16)",
                        "nullable": false
                    },
                    {
                        "name": "scanner_identity",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "engine_id",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "engine_version",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "signature_set_id",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "signature_version",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "observed_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "received_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "auth_scheme_version",
                        "type": "character varying(64)",
                        "nullable": false
                    },
                    {
                        "name": "key_epoch_reference",
                        "type": "character varying(128)",
                        "nullable": false
                    }
                ]
            },
            {
                "name": "proof_scan_audit_events",
                "columns": [
                    {
                        "name": "id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "event_key",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "correlation_id",
                        "type": "uuid",
                        "nullable": false
                    },
                    {
                        "name": "recorded_at",
                        "type": "timestamp(6) with time zone",
                        "nullable": false
                    },
                    {
                        "name": "event_type",
                        "type": "character varying(48)",
                        "nullable": false
                    },
                    {
                        "name": "reason_code",
                        "type": "character varying(48)",
                        "nullable": false
                    },
                    {
                        "name": "principal_kind",
                        "type": "character varying(32)",
                        "nullable": false
                    },
                    {
                        "name": "principal_ref",
                        "type": "character varying(128)",
                        "nullable": false
                    },
                    {
                        "name": "subject_id",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "job_id",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "attempt_id",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "verdict_id",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "policy_id",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "admission_incarnation",
                        "type": "uuid",
                        "nullable": true
                    },
                    {
                        "name": "generation",
                        "type": "bigint",
                        "nullable": true
                    },
                    {
                        "name": "fence",
                        "type": "bigint",
                        "nullable": true
                    },
                    {
                        "name": "policy_epoch",
                        "type": "bigint",
                        "nullable": true
                    },
                    {
                        "name": "authority_ref",
                        "type": "character varying(256)",
                        "nullable": true
                    },
                    {
                        "name": "descriptor_digest",
                        "type": "character(64)",
                        "nullable": true
                    },
                    {
                        "name": "event_digest",
                        "type": "character(64)",
                        "nullable": false
                    },
                    {
                        "name": "audience_ref",
                        "type": "character varying(128)",
                        "nullable": true
                    },
                    {
                        "name": "authorization_binding_digest",
                        "type": "character(64)",
                        "nullable": true
                    },
                    {
                        "name": "subject_state_version",
                        "type": "bigint",
                        "nullable": true
                    },
                    {
                        "name": "subject_state",
                        "type": "character varying(24)",
                        "nullable": true
                    }
                ]
            }
        ],
        "constraints": [
            {
                "table": "proof_scan_policies",
                "name": "psp_version_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psp_version(version, definition_version)"
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_digest_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psp_digest(definition_digest)"
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_profile_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psp_profile(stage_plan, allowed_versions, retry_profile, freshness_profile, resource_profile, approval_reference, created_by_function)"
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_scope_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psh_scope(scope_key, admission, authority_ref)"
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_epoch_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psh_epoch(epoch)"
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_tuple_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_pss_tuple(proof_disk, proof_path, proof_reference, proof_sha256, subject_digest, proof_size_bytes, proof_mime, descriptor_version)"
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_versions_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_pss_versions(generation, state_version, policy_epoch, changed_at, created_at)"
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_state_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_pss_state(state)"
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_hold_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_pss_hold(state, held_reason, security_hold, hold_authority_ref, hold_reason)"
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_state_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psj_state(state, attempt_count, current_attempt_id, terminal_reason)"
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_counts_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psj_counts(attempt_count, generation, policy_epoch, fence)"
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_stage_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psj_stage(stage_id, stage_plan_digest, origin, requested_by_function, authority_ref)"
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_times_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psj_times(created_at, changed_at, not_before)"
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_state_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psa_state(state)"
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_ordinal_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psa_ordinal(attempt_no, generation, fence, identity_epoch)"
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_times_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psa_times(leased_at, lease_deadline, started_at, completed_at)"
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_shape_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psa_shape(state, started_at, completed_at, failure_code, scanner_identity, deployment_audience)"
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_shape_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psv_shape(verdict, reason_code, subject_digest, observed_sha256, envelope_digest, observed_size_bytes, observed_mime, generation, fence, policy_epoch, identity_epoch, stage_id, observed_at, received_at)"
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_simulation_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_psv_simulation(scanner_identity, engine_id, engine_version, signature_set_id, signature_version, auth_scheme_version, key_epoch_reference)"
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_digest_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_pse_digest(event_digest)"
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_event_shape_ck",
                "type": "c",
                "columns": [],
                "call": "proof_scan_pse_event_shape(event_type, reason_code, principal_kind, principal_ref, subject_id, job_id, attempt_id, verdict_id, policy_id, admission_incarnation, generation, fence, policy_epoch, authority_ref, descriptor_digest, audience_ref, authorization_binding_digest, subject_state_version, subject_state, recorded_at)"
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_pkey",
                "type": "p",
                "columns": [
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_payment_uq",
                "type": "u",
                "columns": [
                    "payment_id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_ref_uq",
                "type": "u",
                "columns": [
                    "proof_reference"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_version_uq",
                "type": "u",
                "columns": [
                    "version"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_digest_uq",
                "type": "u",
                "columns": [
                    "definition_digest"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_scope_uq",
                "type": "u",
                "columns": [
                    "scope_key"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_stage_uq",
                "type": "u",
                "columns": [
                    "subject_id",
                    "generation",
                    "stage_id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_bind_uq",
                "type": "u",
                "columns": [
                    "id",
                    "subject_id",
                    "policy_id",
                    "generation",
                    "policy_epoch",
                    "stage_id",
                    "admission_incarnation"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_challenge_uq",
                "type": "u",
                "columns": [
                    "challenge_id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_ordinal_uq",
                "type": "u",
                "columns": [
                    "job_id",
                    "attempt_no"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_fence_uq",
                "type": "u",
                "columns": [
                    "job_id",
                    "fence"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_job_id_uq",
                "type": "u",
                "columns": [
                    "job_id",
                    "id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_bind_uq",
                "type": "u",
                "columns": [
                    "id",
                    "job_id",
                    "generation",
                    "fence",
                    "admission_incarnation"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_attempt_uq",
                "type": "u",
                "columns": [
                    "attempt_id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_identity_result_uq",
                "type": "u",
                "columns": [
                    "scanner_identity",
                    "result_id"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_event_key_uq",
                "type": "u",
                "columns": [
                    "event_key"
                ],
                "ref": null,
                "refcols": null,
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_payment_fk",
                "type": "f",
                "columns": [
                    "invoice_id",
                    "payment_id"
                ],
                "ref": "payments",
                "refcols": [
                    "invoice_id",
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_revision_fk",
                "type": "f",
                "columns": [
                    "invoice_id",
                    "revision_id"
                ],
                "ref": "invoice_revisions",
                "refcols": [
                    "invoice_id",
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_submitter_fk",
                "type": "f",
                "columns": [
                    "submitter_id"
                ],
                "ref": "users",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_request_fk",
                "type": "f",
                "columns": [
                    "billing_request_id",
                    "invoice_id",
                    "submitter_id"
                ],
                "ref": "billing_requests",
                "refcols": [
                    "id",
                    "invoice_id",
                    "actor_id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_action_fk",
                "type": "f",
                "columns": [
                    "billing_action_id"
                ],
                "ref": "billing_actions",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_policy_fk",
                "type": "f",
                "columns": [
                    "policy_id"
                ],
                "ref": "proof_scan_policies",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_policy_fk",
                "type": "f",
                "columns": [
                    "policy_id"
                ],
                "ref": "proof_scan_policies",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_subject_fk",
                "type": "f",
                "columns": [
                    "subject_id"
                ],
                "ref": "proof_scan_subjects",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_policy_fk",
                "type": "f",
                "columns": [
                    "policy_id"
                ],
                "ref": "proof_scan_policies",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_current_attempt_fk",
                "type": "f",
                "columns": [
                    "id",
                    "current_attempt_id"
                ],
                "ref": "proof_scan_attempts",
                "refcols": [
                    "job_id",
                    "id"
                ],
                "deferred": true
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_job_fk",
                "type": "f",
                "columns": [
                    "job_id"
                ],
                "ref": "proof_scan_jobs",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_subject_fk",
                "type": "f",
                "columns": [
                    "subject_id"
                ],
                "ref": "proof_scan_subjects",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_job_bind_fk",
                "type": "f",
                "columns": [
                    "job_id",
                    "subject_id",
                    "policy_id",
                    "generation",
                    "policy_epoch",
                    "stage_id",
                    "admission_incarnation"
                ],
                "ref": "proof_scan_jobs",
                "refcols": [
                    "id",
                    "subject_id",
                    "policy_id",
                    "generation",
                    "policy_epoch",
                    "stage_id",
                    "admission_incarnation"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_attempt_bind_fk",
                "type": "f",
                "columns": [
                    "attempt_id",
                    "job_id",
                    "generation",
                    "fence",
                    "admission_incarnation"
                ],
                "ref": "proof_scan_attempts",
                "refcols": [
                    "id",
                    "job_id",
                    "generation",
                    "fence",
                    "admission_incarnation"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_policy_fk",
                "type": "f",
                "columns": [
                    "policy_id"
                ],
                "ref": "proof_scan_policies",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_subject_fk",
                "type": "f",
                "columns": [
                    "subject_id"
                ],
                "ref": "proof_scan_subjects",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_job_fk",
                "type": "f",
                "columns": [
                    "job_id"
                ],
                "ref": "proof_scan_jobs",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_attempt_fk",
                "type": "f",
                "columns": [
                    "attempt_id"
                ],
                "ref": "proof_scan_attempts",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_verdict_fk",
                "type": "f",
                "columns": [
                    "verdict_id"
                ],
                "ref": "proof_scan_verdicts",
                "refcols": [
                    "id"
                ],
                "deferred": false
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_policy_fk",
                "type": "f",
                "columns": [
                    "policy_id"
                ],
                "ref": "proof_scan_policies",
                "refcols": [
                    "id"
                ],
                "deferred": false
            }
        ],
        "indexes": [
            {
                "table": "proof_scan_subjects",
                "name": "pss_state_changed_idx",
                "columns": [
                    "state",
                    "changed_at",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_policy_epoch_idx",
                "columns": [
                    "policy_id",
                    "policy_epoch",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_created_idx",
                "columns": [
                    "created_at",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_ready_idx",
                "columns": [
                    "not_before",
                    "id"
                ],
                "unique": false,
                "predicate": "proof_scan_ready(state)"
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_subject_gen_idx",
                "columns": [
                    "subject_id",
                    "generation",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_active_job_uq",
                "columns": [
                    "job_id"
                ],
                "unique": true,
                "predicate": "proof_scan_active(state)"
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_runtime_active_uq",
                "columns": [
                    "admission_incarnation"
                ],
                "unique": true,
                "predicate": "proof_scan_active(state)"
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_expiry_idx",
                "columns": [
                    "lease_deadline",
                    "id"
                ],
                "unique": false,
                "predicate": "proof_scan_active(state)"
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_subject_stage_idx",
                "columns": [
                    "subject_id",
                    "generation",
                    "stage_id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_received_idx",
                "columns": [
                    "received_at",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_subject_time_idx",
                "columns": [
                    "subject_id",
                    "recorded_at",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_type_time_idx",
                "columns": [
                    "event_type",
                    "recorded_at",
                    "id"
                ],
                "unique": false,
                "predicate": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_subject_version_uq",
                "columns": [
                    "subject_id",
                    "subject_state_version"
                ],
                "unique": true,
                "predicate": "proof_scan_mutation(event_type)"
            }
        ],
        "triggers": [
            {
                "table": "proof_scan_subjects",
                "name": "pss_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_subjects",
                "name": "pss_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_policies",
                "name": "psp_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_policy_heads",
                "name": "psh_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_jobs",
                "name": "psj_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_attempts",
                "name": "psa_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_verdicts",
                "name": "psv_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_write",
                "fn": "proof_scan_guard_row",
                "type": 31,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_truncate",
                "fn": "proof_scan_guard_truncate",
                "type": 34,
                "deferred": false,
                "when": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_final",
                "fn": "proof_scan_validate_graph",
                "type": 21,
                "deferred": true,
                "when": null
            },
            {
                "table": "proof_scan_audit_events",
                "name": "pse_shape",
                "fn": "proof_scan_guard_audit",
                "type": 7,
                "deferred": false,
                "when": null
            },
            {
                "table": "payments",
                "name": "proof_scan_payment_initial_final",
                "fn": "proof_scan_validate_initial_payment",
                "type": 5,
                "deferred": true,
                "when": "proof_scan_initial_candidate(new.billing_schema_version, new.submission_channel)"
            }
        ]
    }
    JSON;

    private ?array $expected = null;

    /** Off-lock only. Loads reviewed definitions, never requires/executes a migration. */
    public function prepareExpected(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new PaymentFailure('proof_scan_preparation_outside_transaction_required');
        }
        $functions = [];
        foreach (self::SOURCES as $path => $hash) {
            $fullPath = base_path($path);
            if (! is_file($fullPath) || is_link($fullPath)) {
                throw new PaymentFailure('proof_scan_source_unavailable');
            }
            $source = str_replace("\r\n", "\n", file_get_contents($fullPath));
            if (! hash_equals($hash, hash('sha256', $source))) {
                throw new PaymentFailure('proof_scan_source_mismatch');
            }
            if (! str_starts_with($path, 'database/')) {
                continue;
            }
            // Nowdoc indentation is eight spaces; mirror PHP's removal without executing it.
            $source = preg_replace('/^ {8}/m', '', $source);
            preg_match_all('/CREATE FUNCTION public\.(proof_scan_[a-z_0-9]+)\((.*?)\) RETURNS ([^\n]+)\nLANGUAGE plpgsql (IMMUTABLE|VOLATILE) SECURITY INVOKER SET search_path = pg_catalog, public AS \$fn\$(.*?)\$fn\$;/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $types = $match[2] === '' ? [] : array_map(
                    static fn (string $arg): string => preg_replace('/\(\d+\)/', '', preg_replace('/^[a-z_0-9]+\s+/', '', trim($arg))),
                    explode(',', $match[2])
                );
                $functions[$match[1]] = ['signature' => $match[1].'('.implode(', ', $types).')',
                    'body' => $match[5], 'result' => $match[3], 'volatility' => $match[4] === 'IMMUTABLE' ? 'i' : 'v',
                    'stage' => str_contains($path, '_000010_') ? 'M10' : 'M11', 'argc' => count($types),
                    'argnames' => $match[2] === '' ? null : array_map(static fn (string $arg): string => explode(' ', trim($arg), 2)[0], explode(',', $match[2]))];
            }
        }
        if (count($functions) !== 50) {
            throw new PaymentFailure('proof_scan_source_inventory_mismatch');
        }
        $this->expected = json_decode(self::CATALOG, true, flags: JSON_THROW_ON_ERROR) + ['functions' => $functions];
    }

    /** DB/catalog only; call after F on fresh/use paths. No filesystem or activation. */
    public function assertReady(bool $completeness = true): void
    {
        $this->requirePrepared();
        if (DB::getDriverName() !== 'pgsql') {
            $this->fail();
        }
        $tableNames = array_column($this->expected['tables'], 'name');
        $names = implode(',', array_fill(0, count($tableNames), '?'));
        $tables = DB::select("SELECT c.relname,c.relkind,c.relpersistence,c.relrowsecurity,c.relforcerowsecurity,
            c.reloptions,c.relowner=(SELECT oid FROM pg_roles WHERE rolname=current_user) AS owned,
            EXISTS (SELECT 1 FROM aclexplode(COALESCE(c.relacl,acldefault('r',c.relowner))) a WHERE a.grantee<>c.relowner) AS extra_acl,
            EXISTS (SELECT 1 FROM pg_inherits h WHERE h.inhrelid=c.oid OR h.inhparent=c.oid) AS inherited
            FROM pg_class c WHERE c.relnamespace='public'::regnamespace AND c.relname IN ({$names})", $tableNames);
        if (count($tables) !== 7 || (int) DB::scalar("SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace AND left(relname,11)='proof_scan_'") !== 7) {
            $this->fail();
        }
        foreach ($tables as $row) {
            if ($row->relkind !== 'r' || $row->relpersistence !== 'p' || ! $row->owned || $row->extra_acl
                || $row->relrowsecurity || $row->relforcerowsecurity || $row->reloptions !== null || $row->inherited) {
                $this->fail();
            }
        }
        foreach ($this->expected['tables'] as $table) {
            $columns = DB::select("SELECT a.attname,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,
                a.attidentity,a.attgenerated,a.atthasdef,a.attcollation=t.typcollation AS default_collation
                FROM pg_attribute a JOIN pg_type t ON t.oid=a.atttypid
                WHERE a.attrelid=?::regclass AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum", ['public.'.$table['name']]);
            if (count($columns) !== count($table['columns'])) {
                $this->fail();
            }
            foreach ($columns as $i => $column) {
                $wanted = $table['columns'][$i];
                if ($column->attname !== $wanted['name'] || $column->type !== $wanted['type']
                    || $column->attnotnull !== ! $wanted['nullable'] || $column->atthasdef
                    || $column->attidentity !== '' || $column->attgenerated !== '' || ! $column->default_collation) {
                    $this->fail();
                }
            }
        }
        $this->assertRoutines($completeness);
        $this->assertConstraints($tableNames);
        $this->assertNotNullConstraints($tableNames);
        $this->assertIndexes($tableNames);
        $this->assertTriggers($tableNames, $completeness);
    }

    private function assertRoutines(bool $completeness): void
    {
        $wanted = array_filter($this->expected['functions'], static fn (array $f): bool => $completeness || $f['stage'] === 'M10');
        $actualNames = DB::select("SELECT proname FROM pg_proc WHERE pronamespace='public'::regnamespace AND left(proname,11)='proof_scan_' ORDER BY proname");
        $actualNames = array_map(static fn (object $row): string => $row->proname, $actualNames);
        $wantedNames = array_keys($wanted);
        sort($wantedNames);
        if ($actualNames !== $wantedNames) {
            $this->fail();
        }
        foreach ($wanted as $function) {
            $row = DB::selectOne("SELECT p.prosrc,p.prosecdef,p.provolatile,p.proconfig,p.prokind,p.proisstrict,p.proleakproof,p.proparallel,
                p.pronargdefaults,p.pronargs,p.proargmodes,p.proretset,p.prosupport::oid AS support,p.protrftypes,array_to_json(p.proargnames)::text AS argnames,
                l.lanname,format_type(p.prorettype,NULL) AS result,
                p.proowner=(SELECT oid FROM pg_roles WHERE rolname=current_user) AS owned,
                EXISTS (SELECT 1 FROM aclexplode(COALESCE(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee<>p.proowner) AS extra_acl,
                has_function_privilege(current_user,p.oid,'EXECUTE') AS executable
                FROM pg_proc p JOIN pg_language l ON l.oid=p.prolang WHERE p.oid=to_regprocedure(?)", ['public.'.$function['signature']]);
            if (! $row || $row->prosrc !== $function['body'] || $row->prosecdef || $row->provolatile !== $function['volatility']
                || $row->proconfig !== '{"search_path=pg_catalog, public"}' || $row->prokind !== 'f'
                || $row->proisstrict || $row->proleakproof || $row->proparallel !== 'u' || $row->lanname !== 'plpgsql'
                || $row->result !== $function['result'] || ! $row->owned || $row->extra_acl || ! $row->executable
                || $row->pronargdefaults !== 0 || $row->pronargs !== $function['argc'] || $row->proargmodes !== null
                || $row->proretset || (int) $row->support !== 0 || $row->protrftypes !== null
                || ($row->argnames === null ? null : json_decode($row->argnames, true, flags: JSON_THROW_ON_ERROR)) !== $function['argnames']) {
                $this->fail();
            }
        }
    }

    private function assertConstraints(array $tables): void
    {
        $names = implode(',', array_fill(0, count($tables), '?'));
        $rows = DB::select("SELECT t.relname,p.conname,p.contype,p.condeferrable,p.condeferred,p.convalidated,p.conenforced,
            p.connoinherit,p.conislocal,p.coninhcount,p.confupdtype,p.confdeltype,p.confmatchtype,r.relname AS parent,
            (SELECT n.nspname FROM pg_namespace n WHERE n.oid=r.relnamespace) AS parent_schema,
            array_to_json(ARRAY(SELECT a.attname::text FROM unnest(p.conkey) WITH ORDINALITY x(num,ord)
                JOIN pg_attribute a ON a.attrelid=p.conrelid AND a.attnum=x.num ORDER BY x.ord))::text AS columns,
            array_to_json(ARRAY(SELECT a.attname::text FROM unnest(p.confkey) WITH ORDINALITY x(num,ord)
                JOIN pg_attribute a ON a.attrelid=p.confrelid AND a.attnum=x.num ORDER BY x.ord))::text AS parent_columns,
            pg_get_expr(p.conbin,p.conrelid) AS expression
            FROM pg_constraint p JOIN pg_class t ON t.oid=p.conrelid LEFT JOIN pg_class r ON r.oid=p.confrelid
            WHERE t.relnamespace='public'::regnamespace AND t.relname IN ({$names}) AND p.contype NOT IN ('t','n')", $tables);
        if (count($rows) !== count($this->expected['constraints'])) {
            $this->fail();
        }
        $rows = array_column($rows, null, 'conname');
        foreach ($this->expected['constraints'] as $wanted) {
            $row = $rows[$wanted['name']] ?? null;
            if (! $row || $row->relname !== $wanted['table'] || $row->contype !== $wanted['type']
                || ! $row->convalidated || ! $row->conenforced || ! $row->conislocal || $row->coninhcount !== 0
                || ($wanted['type'] === 'c' && $row->connoinherit) || $row->condeferrable !== ($wanted['deferred'] ?? false)
                || $row->condeferred !== ($wanted['deferred'] ?? false)) {
                $this->fail();
            }
            if ($wanted['type'] === 'c') {
                if ($this->expression($row->expression) !== $this->expression($wanted['call'])) {
                    $this->fail();
                }
            } elseif (json_decode($row->columns, true, flags: JSON_THROW_ON_ERROR) !== $wanted['columns']) {
                $this->fail();
            }
            if ($wanted['type'] === 'f' && ($row->parent !== $wanted['ref'] || $row->parent_schema !== 'public'
                || $row->confupdtype !== 'a' || $row->confdeltype !== 'a' || $row->confmatchtype !== 's'
                || json_decode($row->parent_columns, true, flags: JSON_THROW_ON_ERROR) !== $wanted['refcols'])) {
                $this->fail();
            }
        }
    }

    /** PostgreSQL 18 catalogs NOT NULL as contype=n, independently of CHECK/keys/FKs. */
    private function assertNotNullConstraints(array $tables): void
    {
        $names = implode(',', array_fill(0, count($tables), '?'));
        $rows = DB::select("SELECT c.relname,p.conname,p.convalidated,p.conenforced,p.condeferrable,p.condeferred,p.conislocal,p.coninhcount,
            cardinality(p.conkey) AS key_count,a.attname FROM pg_constraint p JOIN pg_class c ON c.oid=p.conrelid
            JOIN pg_attribute a ON a.attrelid=p.conrelid AND a.attnum=p.conkey[1]
            WHERE c.relnamespace='public'::regnamespace AND c.relname IN ({$names}) AND p.contype='n'", $tables);
        $rowsByName = array_column($rows, null, 'conname');
        $prefixes = ['proof_scan_subjects' => 'pss', 'proof_scan_policies' => 'psp', 'proof_scan_policy_heads' => 'psh',
            'proof_scan_jobs' => 'psj', 'proof_scan_attempts' => 'psa', 'proof_scan_verdicts' => 'psv', 'proof_scan_audit_events' => 'pse'];
        $count = 0;
        foreach ($this->expected['tables'] as $table) {
            foreach ($table['columns'] as $column) {
                if ($column['nullable']) {
                    continue;
                }
                $count++;
                $row = $rowsByName[$prefixes[$table['name']].'_'.$column['name'].'_nn'] ?? null;
                if (! $row || $row->relname !== $table['name'] || $row->attname !== $column['name']
                    || ! $row->convalidated || ! $row->conenforced || $row->condeferrable || $row->condeferred
                    || ! $row->conislocal || $row->coninhcount !== 0 || (int) $row->key_count !== 1) {
                    $this->fail();
                }
            }
        }
        if (count($rows) !== $count || $count !== 112) {
            $this->fail();
        }
    }

    private function assertIndexes(array $tables): void
    {
        $wanted = $this->expected['indexes'];
        foreach ($this->expected['constraints'] as $constraint) {
            if (in_array($constraint['type'], ['p', 'u'], true)) {
                $wanted[] = ['table' => $constraint['table'], 'name' => $constraint['name'], 'columns' => $constraint['columns'],
                    'unique' => true, 'predicate' => null];
            }
        }
        $names = implode(',', array_fill(0, count($tables), '?'));
        $rows = DB::select("SELECT t.relname,i.relname AS name,x.indisvalid,x.indisready,x.indislive,x.indisunique,x.indnullsnotdistinct,x.indimmediate,
            x.indnkeyatts,x.indnatts,pg_get_expr(x.indpred,x.indrelid) AS predicate,x.indexprs,m.amname,i.reloptions,
            array_to_json(ARRAY(SELECT a.attname::text FROM unnest(x.indkey) WITH ORDINALITY k(num,ord)
                JOIN pg_attribute a ON a.attrelid=x.indrelid AND a.attnum=k.num ORDER BY k.ord))::text AS columns,
            EXISTS(SELECT 1 FROM unnest(x.indclass) op JOIN pg_opclass c ON c.oid=op WHERE NOT c.opcdefault) AS nondefault_opclass,
            EXISTS(SELECT 1 FROM unnest(x.indoption) opt WHERE opt<>0) AS nondefault_option,
            EXISTS(SELECT 1 FROM unnest(x.indkey,x.indcollation) pair(num,col) JOIN pg_attribute a ON a.attrelid=x.indrelid AND a.attnum=pair.num WHERE a.attcollation<>pair.col) AS nondefault_collation
            FROM pg_index x JOIN pg_class t ON t.oid=x.indrelid JOIN pg_class i ON i.oid=x.indexrelid JOIN pg_am m ON m.oid=i.relam
            WHERE t.relnamespace='public'::regnamespace AND t.relname IN ({$names})", $tables);
        if (count($rows) !== count($wanted)) {
            $this->fail();
        }
        $rows = array_column($rows, null, 'name');
        foreach ($wanted as $index) {
            $row = $rows[$index['name']] ?? null;
            if (! $row || $row->relname !== $index['table'] || ! $row->indisvalid || ! $row->indisready || ! $row->indislive
                || $row->indisunique !== $index['unique'] || $row->indnullsnotdistinct || ! $row->indimmediate
                || (int) $row->indnkeyatts !== count($index['columns']) || (int) $row->indnatts !== count($index['columns'])
                || $row->indexprs !== null || $row->amname !== 'btree' || $row->reloptions !== null
                || $row->nondefault_opclass || $row->nondefault_option || $row->nondefault_collation
                || json_decode($row->columns, true, flags: JSON_THROW_ON_ERROR) !== $index['columns']
                || $this->expression($row->predicate) !== $this->expression($index['predicate'])) {
                $this->fail();
            }
        }
    }

    private function assertTriggers(array $tables, bool $completeness): void
    {
        $wanted = array_filter($this->expected['triggers'], static fn (array $t): bool => $completeness || $t['table'] !== 'payments');
        foreach ($wanted as $trigger) {
            $row = DB::selectOne("SELECT t.tgtype,t.tgenabled,t.tgdeferrable,t.tginitdeferred,t.tgnargs,octet_length(t.tgargs) AS arg_bytes,
                t.tgattr::text AS attrs,t.tgnewtable,t.tgoldtable,p.proname,n.nspname,pg_get_triggerdef(t.oid) AS definition,
                c.contype,c.condeferrable,c.condeferred,c.conname
                FROM pg_trigger t JOIN pg_proc p ON p.oid=t.tgfoid JOIN pg_namespace n ON n.oid=p.pronamespace
                LEFT JOIN pg_constraint c ON c.oid=t.tgconstraint
                WHERE t.tgrelid=?::regclass AND t.tgname=? AND NOT t.tgisinternal", ['public.'.$trigger['table'], $trigger['name']]);
            if (! $row || (int) $row->tgtype !== $trigger['type'] || $row->tgenabled !== 'O'
                || $row->tgdeferrable !== $trigger['deferred'] || $row->tginitdeferred !== $trigger['deferred']
                || $row->tgnargs !== 0 || (int) $row->arg_bytes !== 0 || $row->attrs !== ''
                || $row->tgnewtable !== null || $row->tgoldtable !== null || $row->proname !== $trigger['fn'] || $row->nspname !== 'public'
                || ($trigger['deferred'] && ($row->contype !== 't' || ! $row->condeferrable || ! $row->condeferred || $row->conname !== $trigger['name']))
                || (! $trigger['deferred'] && $row->contype !== null)) {
                $this->fail();
            }
            preg_match('/ WHEN \((.*)\) EXECUTE FUNCTION /', $row->definition, $match);
            if ($this->expression($match[1] ?? null) !== $this->expression($trigger['when'])) {
                $this->fail();
            }
        }
        $names = implode(',', array_fill(0, count($tables), '?'));
        $count = DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
            WHERE c.relnamespace='public'::regnamespace AND c.relname IN ({$names}) AND NOT t.tgisinternal", $tables);
        if ((int) $count !== 22) {
            $this->fail();
        }
        if (! $completeness && (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid='public.payments'::regclass AND tgname='proof_scan_payment_initial_final'") !== 0) {
            $this->fail();
        }
    }

    /** Source-derived signatures only, prepared off-lock. Empty-owned reversal needs separate permission. */
    public function removalStatements(bool $completenessOnly): array
    {
        $this->requirePrepared();
        if ($completenessOnly) {
            return ['DROP TRIGGER proof_scan_payment_initial_final ON public.payments',
                'DROP FUNCTION public.proof_scan_validate_initial_payment()',
                'DROP FUNCTION public.proof_scan_initial_candidate(smallint, character varying)'];
        }
        $functions = array_reverse(array_filter($this->expected['functions'], static fn (array $f): bool => $f['stage'] === 'M10'));
        $result = [];
        foreach ($this->expected['triggers'] as $trigger) {
            if ($trigger['table'] !== 'payments') {
                $result[] = 'DROP TRIGGER '.$trigger['name'].' ON public.'.$trigger['table'];
            }
        }
        foreach ($functions as $function) {
            if ($function['volatility'] === 'v') {
                $result[] = 'DROP FUNCTION public.'.$function['signature'];
            }
        }
        $result[] = 'ALTER TABLE public.proof_scan_jobs DROP CONSTRAINT psj_current_attempt_fk';
        foreach (['audit_events', 'verdicts', 'attempts', 'jobs', 'subjects', 'policy_heads', 'policies'] as $name) {
            $result[] = 'DROP TABLE public.proof_scan_'.$name;
        }
        foreach ($functions as $function) {
            if ($function['volatility'] === 'i') {
                $result[] = 'DROP FUNCTION public.'.$function['signature'];
            }
        }

        return $result;
    }

    private function expression(?string $expression): ?string
    {
        return $expression === null ? null : strtolower(preg_replace('/[\s()"]/', '', str_replace('public.', '', $expression)));
    }

    private function requirePrepared(): void
    {
        if ($this->expected === null) {
            throw new PaymentFailure('proof_scan_source_not_prepared');
        }
    }

    private function fail(): never
    {
        throw new PaymentFailure('proof_scan_schema_unavailable');
    }
}
