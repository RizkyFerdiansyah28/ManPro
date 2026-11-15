<?php

namespace Tests\Support;

use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Billing\BillingTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

abstract class InvoiceHttpTestCase extends InvoiceConcurrencyTestCase
{
    private array $secretBodies = [];

    protected const HTTP_BASE = '/admin/billing/invoices';

    protected string $evidenceEnvironment = 'SILANSIA_P93B_EVIDENCE_FILE';

    protected string $evidencePrefix = 'SILANSIA_P9_3B_TEST_EVIDENCE_';

    protected function previewBody(array $s): array
    {
        return ['elderly_id' => $s['elderly']->id, 'billing_month' => '2026-10-01'];
    }

    protected function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)
            ->version(Request::create('/')) ?? ''];
    }

    protected function spawn(array $payload): int
    {
        if (($payload['operation'] ?? '') !== 'http') {
            return parent::spawn($payload);
        }
        $body = $payload['body'];
        unset($payload['body']);
        $index = parent::spawn($payload);
        $this->secretBodies[$index] = $body;

        return $index;
    }

    protected function release(int $worker): void
    {
        if (array_key_exists($worker, $this->secretBodies)) {
            // Opaque token melewati pipe, bukan argv, environment, stdout atau trace.
            $this->workers[$worker][1]->write(json_encode($this->secretBodies[$worker], JSON_THROW_ON_ERROR)."\n");
            unset($this->secretBodies[$worker]);
        }
        parent::release($worker);
    }

    protected function httpPayload(array $s, array $body, string $suffix = '', array $pause = [], string $fault = ''): array
    {
        return ['operation' => 'http', 'actor' => $s['admin']->id, 'resident' => $s['elderly']->id,
            'method' => 'POST', 'path' => self::HTTP_BASE.$suffix, 'body' => $body, 'pause' => $pause, 'fault' => $fault];
    }

    protected function httpCommand(array $s): array
    {
        $response = $this->actingAs($s['admin'])->postJson(self::HTTP_BASE.'/preview', $this->previewBody($s));
        $response->assertOk()->assertJsonPath('kind', 'preview')->assertJsonPath('issued', false)->assertJsonPath('decision', 'ISSUE');
        $this->assertNoPrivateError($response);

        return [...$this->previewBody($s), 'request_id' => $response->json('confirmation.request_id'),
            'confirmation_token' => $response->json('confirmation.token'), 'confirm' => true];
    }

    protected function reconcileBody(array $command): array
    {
        unset($command['confirm']);

        return $command;
    }

    protected function changedToken(array $command, array $changes): array
    {
        // Synthetic signed token fixture; no App clock/key or production envelope mutation.
        $data = json_decode(Crypt::decryptString($command['confirmation_token']), true, flags: JSON_THROW_ON_ERROR);
        $command['confirmation_token'] = Crypt::encryptString(json_encode(array_replace($data, $changes), JSON_THROW_ON_ERROR));

        return $command;
    }

    protected function expired(array $command): array
    {
        $at = DB::scalar("SELECT to_char((clock_timestamp()-INTERVAL '6 minutes') AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");
        $end = BillingTime::format(BillingTime::instant($at)->modify('+5 minutes'));

        return $this->changedToken($command, ['issued_at' => $at, 'expires_at' => $end]);
    }

    protected function assertNoPrivateError($response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['SQLSTATE', 'PDOException', 'source_fingerprint', 'intent_hash', 'content_hash', 'private_note', 'Stack trace'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }
}
