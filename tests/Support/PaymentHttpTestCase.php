<?php

namespace Tests\Support;

use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\LocalPaymentProofStore;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\Billing\PaymentProofStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class PaymentHttpTestCase extends PaymentCoreTestCase
{
    protected string $evidenceEnvironment = 'SILANSIA_P94C_EVIDENCE_FILE';

    protected string $evidencePrefix = 'SILANSIA_P9_4C_TEST_EVIDENCE_';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment_http.enabled' => true, 'payment_http.synthetic_validation' => true]);
        $this->app->forgetInstance(PaymentProofStore::class);
        $this->app->bind(PaymentProofStore::class, LocalPaymentProofStore::class);
        // Enable the real token/origin checks that Laravel normally bypasses in PHPUnit.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->withSession(['_token' => 'synthetic-p94c-csrf']);
        $this->withCookie(config('session.cookie'), app('session')->driver()->getId());
        $this->withCredentials(); // Laravel JSON helpers otherwise omit cookies entirely.
        $this->withHeaders(['Accept' => 'application/json', 'X-CSRF-TOKEN' => 'synthetic-p94c-csrf']);
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe(app(), DB::connection());
        $this->assertStringStartsWith(realpath(dirname($guard->identity['cache'])), app(PaymentProofEnvironment::class)->root());
        $evidence = base_path('docs/report/SILANSIA_P9_4C_ISOLATION_'.substr($guard->identity['database'], -16).'.json');
        if (! file_exists($evidence)) {
            $this->assertNotFalse(file_put_contents($evidence, json_encode([
                'environment' => app()->environment(), 'connection' => config('database.default'),
                'actual_database' => DB::scalar('SELECT current_database()'), 'host' => DB::connection()->getConfig('host'),
                'port' => (string) DB::connection()->getConfig('port'), 'owned_identity_verified' => true,
                'config_cached' => app()->configurationIsCached(), 'cache_isolated' => true,
                'store_implementation' => get_class(app(PaymentProofStore::class)),
                'storage_root_kind' => 'canonical-owned-run-cache-parent/p94c-proofs',
                'development_storage_used' => false, 'admission' => 'synthetic_owned_testing_only',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        }
    }

    protected function httpSource(array $changes = []): array
    {
        $s = $this->source($changes);
        $issue = app(InvoiceIssuanceService::class)->issue($this->command($s));

        return $s + ['issue' => $issue, 'invoice_url' => '/keluarga/billing/invoices/'.$issue->invoiceId];
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        // Fixture account switching is a new authenticated actor, not reuse of another
        // actor's AuthenticateSession password hash. Production middleware is unchanged.
        $session = $this->app['session']->driver();
        $session->put('password_hash_'.($guard ?? 'web'), $user->getAuthPassword());
        $session->save();

        return parent::actingAs($user, $guard);
    }

    protected function image(string $type = 'png', int $width = 2, int $height = 2): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);
        $bytes = ob_get_clean();
        unset($image);

        return $bytes;
    }

    protected function upload(?string $bytes = null, string $name = 'receipt.png'): UploadedFile
    {
        $root = app(PaymentProofEnvironment::class)->root();
        if (! is_dir($root)) {
            $this->assertTrue(mkdir($root, 0700));
        }
        $path = $root.'/synthetic-upload-'.Str::uuid();
        $this->assertSame(strlen($bytes ??= $this->image()), file_put_contents($path, $bytes));

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function submitHttp(array $s, ?string $token = null, ?UploadedFile $file = null, array $extra = [])
    {
        $this->actingAs($s['family']);
        $token ??= $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');

        return $this->post($s['invoice_url'].'/payments', ['command_context' => $token, 'confirm' => '1', 'proof' => $file ?? $this->upload(), ...$extra],
            ['Content-Type' => 'multipart/form-data; boundary=synthetic-p94c']);
    }

    protected function reviewUrl(array $s, string $payment): string
    {
        return '/admin/billing/invoices/'.$s['issue']->invoiceId.'/payments/'.$payment;
    }

    protected function reconcileHttp(string $token)
    {
        $fresh = $this->getJson('/billing/payment-commands/recovery-context')->assertOk()->json('recovery_context');

        return $this->postJson('/billing/payment-commands/reconcile', ['command_context' => $token, 'recovery_context' => $fresh]);
    }

    protected function assertPrivate($response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['SQLSTATE', 'Stack trace', 'proof_path', 'proof_sha256', 'verification_notes', 'private_note', 'storage/app', 'intent_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $input]) {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if ($this->app && config('payment_http.enabled') === true) {
            PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
            $root = app(PaymentProofEnvironment::class)->root();
            if (is_dir($root)) {
                $remove = function (string $path) use (&$remove, $root) {
                    $this->assertStringStartsWith($root, $path);
                    $this->assertFalse(is_link($path));
                    app(PaymentProofEnvironment::class)->confined($path);
                    if (is_dir($path)) {
                        foreach (scandir($path) as $child) {
                            if ($child !== '.' && $child !== '..') {
                                $remove($path.'/'.$child);
                            }
                        }
                        $this->assertTrue(rmdir($path));
                    } else {
                        $this->assertTrue(unlink($path));
                    }
                };
                $remove($root); // Only synthetic disposable objects beneath the exact owned-run root.
            }
        }
        parent::tearDown();
    }
}
