<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Support\Billing\ProofAuditBinding;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PaymentProofUse
{
    public function __construct(
        private readonly LocalPaymentProofStore $store,
        private readonly ProofAuditBinding $binding,
    ) {}

    /** @return array{bytes: string, mime: string, grant: Closure} */
    public function prepare(string $kind, string $actor, object $invoice, object $payment): array
    {
        // Only successful preparation initializes this fresh schema contract.
        $schema = new ProofScanSchemaContract;
        $readiness = app()->makeWith(ProofScanReadiness::class, ['schema' => $schema]);
        $readiness->prepare();
        $schema->assertReady();
        $proof = StoredPaymentProof::fromRow($payment);
        $descriptor = $this->descriptor($proof);
        // Off-lock candidate only. M10 rechecks this version and descriptor under P/Q SHARE.
        $subject = DB::table('public.proof_scan_subjects')->where('payment_id', $payment->id)
            ->where('invoice_id', $invoice->id)->where('revision_id', $payment->invoice_revision_id)
            ->where($descriptor)->first(['id', 'state_version']);
        $version = filter_var($subject?->state_version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($subject === null || $version === false) {
            throw new PaymentFailure('proof_use_unavailable');
        }

        $authority = $this->authority();
        $bytes = $this->store->bytes($proof, $payment->submitted_by, $invoice->id);
        $binding = $this->binding->prepare($kind, $authority, $actor, $invoice->id, $payment->id, $subject->id, $version);
        $revision = $payment->invoice_revision_id;
        $currentRevision = $invoice->current_revision_id;
        $submitter = $payment->submitted_by;

        $grant = function (string $actor, object $invoice, object $payment) use ($readiness, $kind, $descriptor, $subject, $version, $binding, $revision, $currentRevision, $submitter): void {
            try {
                $readiness->assertFreshReady();
                if ($invoice->current_revision_id !== $currentRevision || $payment->invoice_id !== $invoice->id
                    || $payment->invoice_revision_id !== $revision || $payment->submitted_by !== $submitter
                    || $this->descriptor(StoredPaymentProof::fromRow($payment)) !== $descriptor) {
                    throw new PaymentFailure('proof_use_unavailable');
                }
                $authority = $this->authority();
                [$audience, $authorization] = $binding($authority, $actor, $invoice->id, $payment->id, $subject->id, $version);
                DB::selectFromWriteConnection(
                    'SELECT public.proof_scan_append_audit(?::uuid, ?::bigint, ?::text, ?::uuid, ?::text, ?::text, ?::jsonb, ?::text)',
                    [$subject->id, $version, $kind, $actor, $audience, $authorization, json_encode($descriptor, JSON_THROW_ON_ERROR), $authority],
                );
            } catch (Throwable) {
                throw new PaymentFailure('proof_use_unavailable');
            }
        };

        return ['bytes' => $bytes, 'mime' => $proof->mime, 'grant' => $grant];
    }

    private function authority(): string
    {
        return DB::scalar('SELECT public.proof_scan_authority()');
    }

    private function descriptor(StoredPaymentProof $proof): array
    {
        return ['descriptor_version' => 1, 'proof_reference' => explode('/', $proof->path)[1] ?? '', ...$proof->row()];
    }
}
