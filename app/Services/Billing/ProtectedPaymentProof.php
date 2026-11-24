<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProtectedPaymentProof
{
    public function __construct(
        private readonly PaymentHttpReader $reader,
        private readonly PaymentProofUse $proofs,
    ) {}

    /** @return array{bytes: string, mime: string} */
    public function download(User $actor, string $invoice, string $payment): array
    {
        $db = null;
        $prepared = null;
        $committing = false;
        try {
            if (DB::transactionLevel() !== 0) {
                throw new PaymentFailure('proof_use_unavailable');
            }
            $observed = $this->reader->invoice($actor, $invoice);
            $row = $this->reader->payment($actor, $invoice, $payment);
            $prepared = $this->proofs->prepare('protected_get_granted', $actor->id, $observed, $row);
            $db = DB::connection();
            // Laravel's commit() relies on PDO throwing on failure.
            if ($db->getPdo()->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION || $db->getPdo()->inTransaction()) {
                throw new PaymentFailure('proof_use_unavailable');
            }

            $db->beginTransaction();
            $db->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ WRITE');
            $db->statement("SET LOCAL lock_timeout='5s'");
            $db->statement("SET LOCAL statement_timeout='15s'");
            $db->statement("SET LOCAL idle_in_transaction_session_timeout='20s'");

            [$root, $row] = $this->lockTarget($actor, $observed, $payment);
            $db->select('SELECT public.billing_validate_invoice(?)', [$invoice]);
            $db->select('SELECT public.billing_validate_ownership(?)', [$root->elderly_id]);
            $prepared['grant']($actor->id, $root, $row);
            unset($prepared['grant']);

            if ($db->transactionLevel() !== 1 || ! $db->getPdo()->inTransaction()) {
                throw new PaymentFailure('proof_use_unavailable');
            }
            $committing = true;
            $db->commit();
            if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
                throw new PaymentFailure('proof_use_unavailable');
            }

            return $prepared;
        } catch (Throwable $error) {
            $prepared = null;
            if ($db !== null) {
                try {
                    if ($db->transactionLevel() > 0) {
                        $db->rollBack();
                    }
                } catch (Throwable) {
                    DB::purge($db->getName());
                    throw new PaymentFailure('proof_use_unavailable');
                }
                if ($committing) {
                    // A failed COMMIT acknowledgement never releases B or retries the grant.
                    DB::purge($db->getName());
                }
            }
            if ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
            throw new PaymentFailure('proof_use_unavailable');
        }
    }

    private function lockTarget(User $actor, object $observed, string $payment): array
    {
        $ids = $this->users($actor->id, $observed->current_family_id, $observed->bill_to_family_id);
        $users = User::whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'role', 'is_active'])->keyBy('id');
        $elderly = DB::table('public.elderlies')->where('id', $observed->elderly_id)->sharedLock()->first(['family_id']);
        abort_unless($elderly && $elderly->family_id === $observed->current_family_id && $users->count() === count($ids), 404);

        $enrollment = DB::table('public.service_enrollments')->where('id', $observed->service_enrollment_id)->sharedLock()->first(['id']);
        DB::table('public.financial_ownership_periods')->where('elderly_id', $observed->elderly_id)->orderBy('id')->sharedLock()->get(['id']);
        $root = DB::table('public.invoices')->where('id', $observed->id)->sharedLock()->first();
        $payments = DB::table('public.payments')->where('invoice_id', $observed->id)->orderBy('id')->sharedLock()->get();
        $row = $payments->firstWhere('id', $payment);

        $active = $users->get($actor->id);
        abort_unless($active && $active->is_active && $active->role === $actor->role
            && in_array($active->role, ['admin', 'keluarga'], true), 403);
        abort_unless($enrollment && $root && $row && $root->billing_schema_version === 1
            && $root->elderly_id === $observed->elderly_id && $root->service_enrollment_id === $enrollment->id
            && $root->current_revision_id === $observed->current_revision_id
            && $root->bill_to_family_id === $observed->bill_to_family_id
            && $this->users($actor->id, $elderly->family_id, $root->bill_to_family_id) === $ids
            && $row->billing_schema_version === 1 && $row->submission_channel === 'family_upload', 404);
        abort_unless($active->role === 'admin'
            || ($active->id === $elderly->family_id && $active->id === $root->bill_to_family_id), 404);

        return [$root, $row];
    }

    private function users(string $actor, string $current, string $frozen): array
    {
        $ids = array_unique([$actor, $current, $frozen]);
        sort($ids, SORT_STRING);

        return array_values($ids);
    }
}
