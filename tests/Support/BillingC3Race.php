<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Billing\AcademicProofScanner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

/** Two independent guarded Laravel/PDO processes with a measured lock overlap. */
final class BillingC3Race
{
    public static function run(string $invoice, array $env): array
    {
        Identity::require(BillingC3Fixtures::target($invoice) === 'rejection', 'billing_c3_race_scope_invalid');
        $input = json_decode(stream_get_contents(STDIN, 32769), true, 8, JSON_THROW_ON_ERROR);
        Identity::require(is_array($input) && array_keys($input) === ['cookie', 'csrf', 'contexts']
            && is_string($input['cookie']) && strlen($input['cookie']) < 4096 && is_string($input['csrf']) && strlen($input['csrf']) < 4096
            && is_array($input['contexts']) && count($input['contexts']) === 2 && $input['contexts'][0] !== $input['contexts'][1], 'billing_c3_race_protocol_invalid');
        foreach ($input['contexts'] as $context) {
            Identity::require(is_string($context) && strlen($context) <= 12000, 'billing_c3_race_protocol_invalid');
        }
        Identity::write(self::path('input'), ['invoice' => $invoice, ...$input]);
        Identity::writeNew(self::path('proof.png'), AcademicProofScanner::fixtureBytes('clean'));

        $processes = [];
        try {
            foreach (['a', 'b'] as $label) {
                $command = BillingC3Bootstrap::phpCommand([__DIR__.'/run-payment-r2-integration.php', '--billing-c3-control', 'race-worker', $invoice, $label], Runtime::root());
                $processes[$label] = BillingC3Process::start($command, Runtime::root(), $env, 115);
            }
            self::await(static function () use ($processes): bool {
                foreach ($processes as $process) {
                    $process->pid();
                }

                return is_file(self::path('ready-a')) && is_file(self::path('ready-b'));
            }, 75);
            $first = Identity::read(self::path('ready-a'));
            $second = Identity::read(self::path('ready-b'));
            Identity::require($first['os'] === $processes['a']->pid() && $second['os'] === $processes['b']->pid()
                && $first['db'] !== $second['db'], 'billing_c3_race_process_mismatch');
            self::signal('go-a', $invoice);
            self::await(static fn (): bool => self::received('commit-a', $invoice), 8);
            self::signal('go-b', $invoice);
            self::await(static fn (): bool => DB::scalar('SELECT ?::int = ANY(pg_blocking_pids(?::int))', [$first['db'], $second['db']]) === true, 3);
            self::signal('release-a', $invoice);
            $outcomes = [];
            foreach ($processes as $label => $process) {
                self::await(static fn (): bool => ! $process->poll(), 15);
                [$code, $output] = $process->await();
                $outcome = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
                Identity::require($code === 0 && ($outcome['ok'] ?? null) === true, 'billing_c3_race_worker_failed');
                $outcomes[$label] = $outcome['race_worker'];
            }
            Identity::require($outcomes['a']['status'] === 201 && $outcomes['b']['status'] === 409
                && $outcomes['b']['body']['error']['code'] === 'pending_payment_exists', 'billing_c3_race_outcome_mismatch');
            $refs = array_map(static function (string $token) use ($invoice): string {
                $context = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
                $reference = $context['proof_reference'] ?? '';
                Identity::require(($context['operation'] ?? null) === 'submit' && ($context['invoice_id'] ?? null) === $invoice
                    && \Illuminate\Support\Str::isUuid($reference), 'billing_c3_race_proof_binding');

                return $reference;
            }, $input['contexts']);
            $payments = DB::table('payments')->where('invoice_id', $invoice)->get(['id', 'proof_path']);
            Identity::require(count($payments) === 1 && $payments[0]->id === $outcomes['a']['body']['payment_id'] && $refs[0] !== $refs[1]
                && $payments[0]->proof_path === 'payment-proofs/'.$refs[0].'/object.png'
                && ! DB::table('payments')->where('proof_path', 'payment-proofs/'.$refs[1].'/object.png')->exists(), 'billing_c3_race_proof_binding');

            return ['overlap' => true, 'fact' => $outcomes['a']['body'], 'conflict' => $outcomes['b']['body']['error']['code'],
                'winner_reference' => $refs[0], 'orphan_reference' => $refs[1]];
        } catch (\Throwable $error) {
            BillingC3ProcessJob::signalFailure();
            throw $error;
        }
    }

    public static function worker(string $invoice, string $label): array
    {
        $data = Identity::read(self::path('input'));
        Identity::require(BillingC3Fixtures::target($invoice) === 'rejection' && $data['invoice'] === $invoice, 'billing_c3_race_scope_invalid');
        // PHPUnit-mode CLI requests still use the actual encrypted session and CSRF token from the browser.
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        if ($label === 'a') {
            app('events')->listen(TransactionCommitting::class, static function () use ($invoice): void {
                if (DB::table('billing_requests')->where(['invoice_id' => $invoice, 'operation' => 'submit'])->exists()) {
                    self::signal('commit-a', $invoice);
                    // Revocation or timeout aborts the transaction. STOP never releases a held COMMIT.
                    self::await(static fn (): bool => self::received('release-a', $invoice), 12);
                }
            });
        }
        Identity::write(self::path('ready-'.$label), ['os' => getmypid(), 'db' => DB::scalar('SELECT pg_backend_pid()')]);
        self::await(static fn (): bool => self::received('go-'.$label, $invoice), 90);
        $request = Request::create(getenv('APP_URL').'/keluarga/billing/invoices/'.$invoice.'/payments', 'POST', [
            'command_context' => $data['contexts'][$label === 'a' ? 0 : 1], 'confirm' => '1',
        ], [config('session.cookie') => rawurldecode($data['cookie'])], [
            'proof' => new UploadedFile(self::path('proof.png'), 'synthetic.png', 'image/png', null, true),
        ], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=synthetic-c3',
            'HTTP_X_XSRF_TOKEN' => rawurldecode($data['csrf'])]);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $body = json_decode($response->getContent(), true, 8, JSON_THROW_ON_ERROR);

        return ['status' => $response->getStatusCode(), 'body' => $response->isSuccessful() ? $body : ['error' => ['code' => $body['error']['code']]]];
    }

    private static function path(string $name): string
    {
        return Runtime::path(Runtime::root().'/c3-race-'.$name);
    }

    private static function signal(string $name, string $invoice): void
    {
        BillingC3ProcessJob::assertLive();
        Identity::writeNew(self::path($name), self::barrierClaim($name, $invoice));
    }

    private static function received(string $name, string $invoice): bool
    {
        $file = self::path($name);

        return is_file($file) && file_get_contents($file, false, null, 0, 65) === self::barrierClaim($name, $invoice);
    }

    private static function barrierClaim(string $name, string $invoice): string
    {
        return hash_hmac('sha256', BillingC3Runtime::run().'|'.$invoice.'|'.$name, getenv('SILANSIA_RUNTIME_OWNER'));
    }

    public static function await(callable $condition, float $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            BillingC3ProcessJob::assertLive();
            clearstatcache();
            if ($condition()) {
                BillingC3ProcessJob::assertDeadline((int) $deadline, 'billing_c3_race_barrier_timeout');
                BillingC3ProcessJob::assertLive();
                return;
            }
            usleep(25_000);
        } while (hrtime(true) < $deadline);

        throw new \LogicException('billing_c3_race_barrier_timeout');
    }
}
