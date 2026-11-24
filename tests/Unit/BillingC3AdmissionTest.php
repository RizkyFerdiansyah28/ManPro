<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC3Admission;
use Tests\Support\BillingC3Runtime;

final class BillingC3AdmissionTest extends TestCase
{
    public function test_c3_parser_keeps_parent_child_and_control_explicit_and_distinct(): void
    {
        self::assertSame('parent', BillingC3Admission::command(['--authorized-billing-c3', 'e2e', 'C:\\postgres', '--approved-manifest', str_repeat('a', 64)]));
        self::assertSame('child', BillingC3Admission::command(['--billing-c3-owned-child', 'C:\\postgres']));
        self::assertSame('control', BillingC3Admission::command(['--billing-c3-control', 'before', 'primary']));
        self::assertFalse(BillingC3Admission::validAction(['transfer', '../cam']));
        self::assertFalse(BillingC3Admission::validAction(['before', 'any-invoice']));
        self::assertFalse(BillingC3Admission::validAction(['migrate', 'manpro_db']));
    }

    public static function invalidArguments(): array
    {
        return [
            [['--authorized-billing-c3', 'preflight']],
            [['--authorized-billing-c3', 'e2e', 'unused']],
            [['--authorized-billing-c3', 'e2e', 'unused', '--approved-manifest', 'unsigned']],
            [['--billing-c3-owned-child']],
            [['--billing-c3-control', 'scan', 'manpro_db', 'external']],
            [['--billing-c3-control', 'migrate', 'manpro_db']],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_launcher_arguments_stop_before_provisioning(array $args): void
    {
        $launcher = dirname(__DIR__, 2).'/tests/Support/run-payment-r2-integration.php';
        $windows = array_intersect_key(getenv(), array_flip(['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir']));
        $process = proc_open([PHP_BINARY, '-n', $launcher, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, dirname($launcher), $windows, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(78, proc_close($process));
        self::assertSame('', $errors);
        self::assertSame(['status' => 'BLOCKED', 'reason' => 'billing_c3_invalid_arguments'], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
    }

    public function test_c2_invalid_preflight_still_uses_the_original_dispatch_and_result(): void
    {
        ob_start();
        try {
            self::assertSame(78, \Tests\Support\BillingC2Preflight::entry(['--authorized-billing-c2', 'e2e']));
            $result = json_decode(ob_get_contents(), true, 8, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        self::assertSame('billing_c2_invalid_command', $result['reason']);
        self::assertSame('baseline', $result['stage']);
        self::assertNull($result['root']);
    }

    public function test_review_candidate_has_the_exact_pinned_source_set_without_admitting_runtime(): void
    {
        $base = dirname(__DIR__, 2);
        $path = $base.'/tests/Support/billing-c3-source-manifest.json';
        $manifest = BillingC3Admission::verifyManifest(hash_file('sha256', $path));
        self::assertSame(BillingC3Admission::sourcePaths($base), array_keys($manifest['files']));
        self::assertCount(7, $manifest['tools']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_manifest_not_approved');
        BillingC3Admission::verifyManifest(str_repeat('0', 64));
    }

    public static function connectionOverrides(): array
    {
        return array_map(static fn ($name) => [$name], ['Db_Url', 'db_database', 'PGOPTIONS', 'pgservice', 'DATABASE_URL',
            'APP_PREVIOUS_KEYS', 'SILANSIA_05E_STAGE', 'SILANSIA_TEST_DB_SOCKET']);
    }

    #[DataProvider('connectionOverrides')]
    public function test_override_names_are_denied_even_if_empty(string $name): void
    {
        BillingC3Runtime::assertConnectionEnvironment(['DB_CONNECTION' => 'silansia_testing', 'APP_ENV' => 'testing']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_external_environment');
        BillingC3Runtime::assertConnectionEnvironment([$name => '']);
    }

    public function test_web_router_has_no_arbitrary_mutation_or_cross_origin_routes(): void
    {
        $origin = 'http://127.0.0.1:51234';
        $id = '00000000-0000-4000-8000-000000000000';
        self::assertTrue(BillingC3Runtime::allowedWeb('POST', '/admin/billing/invoices', $origin, $origin));
        self::assertTrue(BillingC3Runtime::allowedWeb('POST', '/keluarga/billing/invoices/'.$id.'/payments', $origin, $origin));
        self::assertTrue(BillingC3Runtime::allowedWeb('GET', '/admin/billing/invoices/'.$id.'/payments/'.$id.'/review-context?operation=verify', $origin, null));
        self::assertFalse(BillingC3Runtime::allowedWeb('POST', '/admin/accounts', $origin, $origin));
        self::assertFalse(BillingC3Runtime::allowedWeb('POST', '/admin/billing/invoices', $origin, 'https://example.test'));
        self::assertFalse(BillingC3Runtime::allowedWeb('GET', '//example.test/login', $origin, null));
        self::assertFalse(BillingC3Runtime::allowedWeb('GET', '/login\\', $origin, null));
        self::assertFalse(BillingC3Runtime::allowedWeb('POST', '/admin/billing/invoices/%2e%2e/accounts', $origin, $origin));
        self::assertTrue(BillingC3Runtime::sameOriginLocation($origin.'/login', $origin));
        self::assertTrue(BillingC3Runtime::sameOriginLocation('/login', $origin));
        self::assertFalse(BillingC3Runtime::sameOriginLocation($origin.'.example.test/login', $origin));
        self::assertFalse(BillingC3Runtime::sameOriginLocation('//example.test/login', $origin));
        self::assertFalse(BillingC3Runtime::sameOriginLocation('/\\example.test/login', $origin));
    }
}
