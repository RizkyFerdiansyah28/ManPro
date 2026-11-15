<?php

namespace App\Support\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceIssuanceFailure;
use App\Services\Billing\InvoiceIssuanceReadiness;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class InvoiceRecoveryContext
{
    public function __construct(private InvoiceIssuanceReadiness $readiness, private Encrypter $encrypter) {}

    public function forRequest(Request $request): array
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $request->hasSession() || ! $request->session()->isStarted()
            || $request->session()->getId() === '') {
            throw new InvoiceHttpException('unauthorized');
        }
        Gate::forUser($actor)->authorize('preview', Invoice::class);
        $this->readiness->entry();
        if (DB::transactionLevel() !== 0) {
            throw new InvoiceHttpException('billing_unavailable');
        }
        try {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ ONLY');
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::statement("SET LOCAL statement_timeout='15s'");
            DB::statement("SET LOCAL idle_in_transaction_session_timeout='20s'");
            $this->readiness->transaction();
            // Identitas audience berasal dari koneksi actual yang telah lolos guard, bukan props/input.
            $row = DB::selectOne("SELECT /* invoice-recovery-context */ current_database() AS name,d.oid::text AS oid,
                EXISTS(SELECT 1 FROM public.users WHERE id=CAST(? AS uuid) AND role='admin' AND is_active) AS authorized
                FROM pg_catalog.pg_database d WHERE datname=current_database()", [$actor->id]);
            if (! $row || ! $row->authorized) {
                throw new InvoiceHttpException('unauthorized');
            }
            $context = self::fingerprints($request->session()->getId(), $actor->id, app()->environment(),
                $row->name, $row->oid, $this->encrypter->getKey());
            DB::commit();

            return $context;
        } catch (Throwable $error) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($error instanceof InvoiceHttpException || $error instanceof InvoiceIssuanceFailure) {
                throw $error;
            }
            throw new InvoiceHttpException('infrastructure_failure');
        }
    }

    /** Pure internal derivation. Markers are not authorization; forRequest() verifies trusted facts. */
    public static function fingerprints(#[\SensitiveParameter] string $sessionId, string $actor,
        string $environment, string $database, string $oid, #[\SensitiveParameter] string $key): array
    {
        return ['version' => 1, 'actor_id' => strtolower($actor),
            'session_context' => hash_hmac('sha256', json_encode(['silansia.invoice.recovery.session.v1', strtolower($actor), $sessionId], JSON_THROW_ON_ERROR), $key),
            'audience_context' => hash_hmac('sha256', json_encode(['silansia.invoice.recovery.audience.v1', $environment, $database, $oid], JSON_THROW_ON_ERROR), $key)];
    }
}
