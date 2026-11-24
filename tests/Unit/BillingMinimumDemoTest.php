<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;
use Tests\Support\BillingC2Preflight;
use Tests\Support\BillingMinimumDemo;
use Tests\Support\BillingMinimumDemoSession;

final class BillingMinimumDemoTest extends TestCase
{
    private const RUN = '0123456789abcdef';

    private const ROOT = 'C:\\Synthetic\\silansia-p94e1-'.self::RUN;

    private const CACHE = self::ROOT.'\\tmp\\silansia-phase1-'.self::RUN.'\\config.php';

    #[DataProvider('deniedCommands')]
    public function test_cli_rejects_before_provisioning(array $args, ?string $mode, ?string $reason): void
    {
        $base = dirname(__DIR__, 2);
        [$code, $stdout] = self::probe([$base.'/tests/Support/run-payment-r2-integration.php', ...$args]);
        self::assertSame(78, $code);
        if ($mode !== null) {
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('BLOCKED', $result['status']);
            self::assertSame($mode, $result['mode'] ?? null);
            self::assertSame($reason, $result['reason']);
            self::assertFalse($result['root_retained']);
        }
    }

    public static function deniedCommands(): iterable
    {
        yield 'demo child without owner' => [['--billing-demo-owned-child', 'unused'], 'billing-minimum-demo', 'runtime_context_missing'];
        yield 'demo does not accept preflight' => [['--authorized-billing-demo', 'preflight', 'unused'], 'billing-minimum-demo', 'billing_c2_invalid_command'];
        yield 'demo requires a binary directory argument' => [['--authorized-billing-demo', 'run'], 'billing-minimum-demo', 'billing_c2_invalid_command'];
        yield 'C2 does not accept demo mode' => [['--authorized-billing-c2', 'run', 'unused'], null, null];
        yield 'C3 does not accept demo mode' => [['--authorized-billing-c3', 'run', 'unused'], null, null];
        yield '05E does not accept demo mode' => [['--authorized-05e2', 'run'], null, null];
    }

    #[DataProvider('invalidIdentities')]
    public function test_demo_requires_exact_owned_target(array $changes): void
    {
        BillingMinimumDemo::assertTargetIdentity(self::identity(), self::ROOT, self::RUN, self::CACHE);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_demo_target_mismatch');
        BillingMinimumDemo::assertTargetIdentity(array_replace(self::identity(), $changes), self::ROOT, self::RUN, self::CACHE);
    }

    public static function invalidIdentities(): iterable
    {
        yield 'development database' => [['database' => 'manpro_db']];
        yield 'other invocation' => [['database' => 'silansia_test_0123456789abcdee']];
        yield 'owner' => [['owner' => 'postgres']];
        yield 'root' => [['runtime_root' => 'C:\\Synthetic\\different']];
        yield 'cache' => [['cache' => 'C:\\Synthetic\\config.php']];
        yield 'wrong server port' => [['port' => '51000']];
        yield 'existing service' => [['port' => '5432', 'connection_port' => '5432']];
        yield 'non-loopback host' => [['host' => '192.0.2.1']];
        yield 'non-loopback server' => [['address' => '192.0.2.1/32']];
    }

    public function test_result_rejects_extra_content_and_pass_requires_final_stage(): void
    {
        self::assertSame(['status' => 'FAIL', 'stage' => 'demo_submit'],
            BillingMinimumDemo::validateResult(['status' => 'FAIL', 'stage' => 'demo_submit']));
        self::assertSame(['status' => 'PASS', 'stage' => 'demo_paid'],
            BillingMinimumDemo::validateResult(['status' => 'PASS', 'stage' => 'demo_paid']));
        foreach ([
            ['status' => 'PASS', 'stage' => 'demo_scan'],
            ['status' => 'FAIL', 'stage' => 'demo_submit', 'payload' => 'synthetic-private'],
        ] as $invalid) {
            try {
                BillingMinimumDemo::validateResult($invalid);
                self::fail('Invalid child result was accepted.');
            } catch (LogicException $error) {
                self::assertSame('billing_demo_result_invalid', $error->getMessage());
            }
        }
        self::assertSame(['child_stage' => 'demo_verify', 'child_reason' => 'billing_demo_outcome_unconfirmed'],
            BillingC2Preflight::childFailureDetails(['status' => 'BLOCKED', 'stage' => 'demo_verify',
                'reason' => 'billing_demo_outcome_unconfirmed'], demo: true));
        self::assertSame(['child_stage' => null, 'child_reason' => null],
            BillingC2Preflight::childFailureDetails(['status' => 'BLOCKED', 'stage' => 'synthetic-private',
                'reason' => 'synthetic-private'], demo: true));
    }

    public function test_application_config_defaults_stay_off_without_loading_dotenv(): void
    {
        $probe = <<<'PHP'
            require 'vendor/autoload.php';
            use Tests\Support\BillingMinimumDemo;
            $active = new ReflectionClassConstant(BillingMinimumDemo::class, 'ACTIVE_CONFIG')->getValue();
            foreach (array_keys($active) as $key) {
                [$file, $entry] = explode('.', $key, 2);
                $default[$key] = (require 'config/'.$file.'.php')[$entry];
            }
            echo json_encode($default, JSON_THROW_ON_ERROR);
            PHP;
        [$code, $stdout] = self::probe(['-r', $probe]);
        self::assertSame(0, $code);
        self::assertSame(['financial_ownership.enabled' => false, 'financial_ownership.cutover_reference' => null,
            'invoice_issuance.enabled' => false, 'payment_core.enabled' => false,
            'payment_http.enabled' => false, 'payment_http.synthetic_validation' => false],
            json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_http_kernel_adapter_isolates_cookies_and_preserves_multipart_file(): void
    {
        $app = new Application;
        $store = $this->createMock(Store::class);
        $store->expects(self::exactly(4))->method('flush');
        $sessions = $this->createStub(SessionManager::class);
        $sessions->method('driver')->willReturn($store);
        $app->instance('session', $sessions);
        $app->instance('auth', new AuthManager($app));
        $upload = new UploadedFile(__FILE__, 'synthetic.png', 'image/png', null, true);
        $cookies = ['a' => 'csrf-a', 'b' => 'csrf-b'];
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects(self::exactly(4))->method('handle')->willReturnCallback(function (Request $request) use ($upload, $cookies) {
            $actor = $request->query('actor');
            $response = new JsonResponse(['actor' => $actor]);
            if ($request->isMethod('GET')) {
                self::assertNull($request->cookie('XSRF-TOKEN'));
                $response->headers->setCookie(new Cookie('XSRF-TOKEN', $cookies[$actor]));
            } else {
                self::assertSame($cookies[$actor], $request->cookie('XSRF-TOKEN'));
                self::assertSame($cookies[$actor], $request->header('X-XSRF-TOKEN'));
                self::assertStringStartsWith('multipart/form-data;', $request->header('Content-Type'));
                self::assertSame($upload, $request->file('proof'));
                self::assertSame('1', $request->input('confirm'));
            }

            return $response;
        });
        $kernel->expects(self::exactly(4))->method('terminate');
        $app->instance(Kernel::class, $kernel);

        $clients = ['a' => new BillingMinimumDemoSession($app), 'b' => new BillingMinimumDemoSession($app)];
        foreach ($clients as $key => $client) {
            self::assertSame(['actor' => $key], $client->json('GET', '/synthetic?actor='.$key, 200));
        }
        foreach ($clients as $key => $client) {
            self::assertSame(['actor' => $key], $client->json('POST', '/synthetic?actor='.$key, 200, ['confirm' => '1'], $upload));
        }
    }

    #[DataProvider('ambiguousWriteResponses')]
    public function test_financial_write_with_unknown_outcome_is_not_retried(string $failure): void
    {
        $app = new Application;
        $sessions = $this->createStub(SessionManager::class);
        $sessions->method('driver')->willReturn($this->createStub(Store::class));
        $app->instance('session', $sessions);
        $app->instance('auth', new AuthManager($app));
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects(self::once())->method('handle')->willReturnCallback(static fn () => match ($failure) {
            'dispatch' => throw new RuntimeException('synthetic financial dispatch error'),
            'http' => new JsonResponse([], 503),
            'reconcile' => new JsonResponse(['error' => ['code' => 'outcome_unresolved']], 409),
            'terminate' => new JsonResponse([], 201),
        });
        $terminate = $kernel->expects($failure === 'dispatch' ? self::never() : self::once())->method('terminate');
        if ($failure === 'terminate') {
            $terminate->willThrowException(new RuntimeException('synthetic termination error'));
        }
        $app->instance(Kernel::class, $kernel);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_demo_outcome_unconfirmed');

        (new BillingMinimumDemoSession($app))->request('POST', '/admin/billing/invoices', 201);
    }

    public static function ambiguousWriteResponses(): iterable
    {
        yield 'exception on dispatch' => ['dispatch'];
        yield 'exception after response' => ['terminate'];
        yield 'ambiguous server error' => ['http'];
        yield 'explicit unresolved outcome' => ['reconcile'];
    }

    private static function probe(array $arguments): array
    {
        $env = array_replace(array_fill_keys(array_keys(getenv()), false), BillingC2Preflight::restrictedEnvironment([]));
        $process = new Process([PHP_BINARY, '-n', ...$arguments], dirname(__DIR__, 2), $env, timeout: 10);

        return [$process->run(), $process->getOutput()];
    }

    private static function identity(): array
    {
        return ['database' => 'silansia_test_'.self::RUN, 'owner' => 'silansia_c2_'.self::RUN,
            'host' => '127.0.0.1', 'address' => '127.0.0.1/32', 'port' => '50123', 'connection_port' => '50123',
            'cache' => self::CACHE, 'runtime_root' => self::ROOT];
    }
}
