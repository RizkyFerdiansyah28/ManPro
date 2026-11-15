<?php

namespace App\Support\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Services\Billing\BillingTime;
use App\Services\Billing\InvoiceIssuanceReadiness;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceIssueConfirmation;
use App\Services\Billing\InvoiceSourceReader;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class InvoiceConfirmationToken
{
    public function __construct(private InvoiceIssuanceReadiness $readiness, private InvoiceSourceReader $reader) {}

    public function mint(string $actor, string $resident, string $month, #[\SensitiveParameter] string $fingerprint): array
    {
        $this->readiness->entry();
        $issued = BillingTime::instant($this->reader->clock());
        $data = ['version' => 1, 'purpose' => 'silansia.invoice.http.issue.v1', 'audience' => $this->audience(),
            'actor_id' => strtolower($actor), 'elderly_id' => strtolower($resident), 'billing_month' => $month,
            'request_id' => (string) Str::uuid(), 'source_fingerprint' => $fingerprint,
            'issued_at' => BillingTime::format($issued), 'expires_at' => BillingTime::format($issued->modify('+5 minutes'))];

        return ['request_id' => $data['request_id'], 'token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
            'expires_at' => $data['expires_at']];
    }

    public function command(string $actor, string $resident, string $month, string $requestId,
        #[\SensitiveParameter] string $token, bool $reconcile = false): InvoiceIssueCommand
    {
        $this->readiness->entry();
        try {
            if (strlen($token) > 4096) {
                throw new \UnexpectedValueException;
            }
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
            $keys = ['version', 'purpose', 'audience', 'actor_id', 'elderly_id', 'billing_month', 'request_id', 'source_fingerprint', 'issued_at', 'expires_at'];
            if (! is_array($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []
                || $data['version'] !== 1 || $data['purpose'] !== 'silansia.invoice.http.issue.v1') {
                throw new \UnexpectedValueException;
            }
            foreach (array_diff($keys, ['version']) as $key) {
                if (! is_string($data[$key])) {
                    throw new \UnexpectedValueException;
                }
            }
            if (! hash_equals($this->audience(), $data['audience']) || $data['actor_id'] !== strtolower($actor)
                || $data['elderly_id'] !== strtolower($resident) || $data['billing_month'] !== $month
                || $data['request_id'] !== strtolower($requestId)) {
                throw new \UnexpectedValueException;
            }
            $issued = BillingTime::instant($data['issued_at']);
            $expires = BillingTime::instant($data['expires_at']);
            $now = BillingTime::instant($this->reader->clock());
            if ($issued > $now || $expires != $issued->modify('+5 minutes')) {
                throw new \UnexpectedValueException;
            }
            $command = new InvoiceIssueCommand($actor, $resident, $month, $requestId, new InvoiceIssueConfirmation($data['source_fingerprint']));
        } catch (Throwable) {
            throw new InvoiceHttpException('confirmation_invalid');
        }
        if (! $reconcile && $now >= $expires) {
            throw new InvoiceHttpException('confirmation_expired');
        }

        return $command;
    }

    private function audience(): string
    {
        // Dibaca hanya sesudah entry() memverifikasi actual DB/role/OID/token/context, tidak dari browser.
        $row = DB::selectOne('SELECT current_database() AS name,d.oid::text AS oid FROM pg_database d WHERE datname=current_database()');

        return hash('sha256', json_encode(['invoice-http-v1', app()->environment(), $row->name, $row->oid], JSON_THROW_ON_ERROR));
    }
}
