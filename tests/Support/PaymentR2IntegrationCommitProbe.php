<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

/** Browser test instrumentation. No route, writer override, trigger or production binding. */
final class PaymentR2IntegrationCommitProbe
{
    private bool $appended = false;

    private ?array $before = null;

    private ?array $committed = null;

    private function __construct(private readonly string $mode, private readonly string $payment, private readonly string $kind) {}

    public static function attach(Request $request): ?self
    {
        $mode = $request->headers->get('X-Silansia-05E-Probe');
        if ($mode === null) {
            return null;
        }
        Identity::require(in_array($mode, ['get-visible', 'get-rollback', 'get-unknown', 'verify-visible', 'verify-rollback', 'verify-unknown'], true),
            'invalid_commit_probe');
        $verify = str_starts_with($mode, 'verify-');
        $pattern = $verify ? '#^/admin/billing/invoices/([a-f0-9-]{36})/payments/([a-f0-9-]{36})/verify$#'
            : '#^/billing/invoices/([a-f0-9-]{36})/payments/([a-f0-9-]{36})/proof$#';
        Identity::require($request->getMethod() === ($verify ? 'POST' : 'GET') && preg_match($pattern, $request->getPathInfo(), $ids) === 1,
            'commit_probe_target_not_allowed');
        PaymentR2IntegrationFixtures::target($ids[1], 'ordering');
        Identity::require(DB::table('payments')->where(['id' => $ids[2], 'invoice_id' => $ids[1], 'status' => 'pending'])->exists(),
            'commit_probe_payment_missing');
        $probe = new self($mode, $ids[2], $verify ? 'verify_use_granted' : 'protected_get_granted');
        $connection = DB::connection();
        $events = app('events');
        $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($probe, $connection): void {
            if ($event->connection === $connection && str_starts_with($event->sql, 'SELECT public.proof_scan_append_audit(')) {
                Identity::require(! $probe->appended, 'double_probe_append');
                $probe->appended = true;
            }
        });
        $events->listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($probe, $connection): void {
            if ($event->connection === $connection && $probe->appended) {
                $probe->before = PaymentR2IntegrationObserver::visibility($probe->payment, $probe->kind);
                if (str_ends_with($probe->mode, '-rollback')) {
                    throw new \RuntimeException('simulated_before_durable_commit');
                }
            }
        });
        $events->listen(TransactionCommitted::class, static function (TransactionCommitted $event) use ($probe, $connection): void {
            if ($event->connection === $connection && $probe->appended) {
                $probe->committed = PaymentR2IntegrationObserver::visibility($probe->payment, $probe->kind);
                if (str_ends_with($probe->mode, '-unknown')) {
                    throw new \RuntimeException('simulated_after_durable_before_ack');
                }
            }
        });

        return $probe;
    }

    public function beforeResponse(Response $response): void
    {
        // Observed on an independent backend before Symfony is allowed to send any byte.
        $after = PaymentR2IntegrationObserver::visibility($this->payment, $this->kind);
        $verify = $this->kind === 'verify_use_granted';
        $rollback = str_ends_with($this->mode, '-rollback');
        $success = str_ends_with($this->mode, '-visible');
        $image = str_starts_with((string) $response->headers->get('content-type'), 'image/');
        Identity::require($this->appended && $this->before !== null && $this->before['grants'] === 0
            && $this->before['verify_facts'] === 0 && $this->before['invoice'] === 'unpaid' && $this->before['payment'] === 'pending'
            && ($rollback ? $this->committed === null && $after === $this->before : $this->committed === $after && $after['grants'] === 1)
            && ($verify && ! $rollback ? $after['same_commit'] && $after['invoice'] === 'paid' && $after['payment'] === 'verified'
                : $after['invoice'] === 'unpaid' && $after['payment'] === 'pending')
            && $response->getStatusCode() === ($success ? 200 : 503) && $image === ($success && ! $verify), 'commit_order_not_proven');
        Identity::write(Runtime::root().'/artifacts/ordering-'.$this->mode.'.json', [
            'before' => $this->before, 'committed' => $this->committed, 'before_response' => $after,
            'http' => $response->getStatusCode(), 'image' => $image,
        ]);
    }
}
