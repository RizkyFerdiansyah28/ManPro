<?php

declare(strict_types=1);

namespace App\Services\Billing;

enum InvoiceReason: string
{
    case Eligible = 'eligible';
    case NoService = 'no_chargeable_service';
    case InvalidMonth = 'invalid_billing_month';
    case InvalidInstant = 'invalid_instant';
    case InvalidInterval = 'invalid_interval';
    case NullRate = 'null_monthly_rate';
    case InvalidRate = 'invalid_monthly_rate';
    case UnsupportedPolicy = 'unsupported_policy';
    case UnsupportedRuntime = 'unsupported_integer_runtime';
    case Overflow = 'integer_overflow';
    case InvalidCommand = 'invalid_command_context';
    case InvalidClock = 'invalid_as_of';
    case MonthNotEnded = 'billing_month_not_ended';
    case ExistingInvoice = 'existing_invoice_root';
    case IncompleteEvidence = 'incomplete_snapshot_evidence';
    case InvalidEnrollment = 'invalid_enrollment_snapshot';
    case MultipleEnrollments = 'multiple_enrollments';
    case MissingLedger = 'ownership_cutover_required';
    case InvalidChain = 'invalid_ownership_chain';
    case StaleOwner = 'stale_current_owner';
    case InvalidAudit = 'invalid_ownership_audit';
    case MixedOwners = 'mixed_owner_calendar_month';
    case PartialCoverage = 'partial_ownership_coverage';
}
