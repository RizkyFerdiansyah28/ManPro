<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\BillingC2Baseline;
use Tests\Support\BillingC2Cluster;
use Tests\Support\BillingC2Preflight;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

final class BillingC2PreflightTest extends TestCase
{
    private const ROOT = 'C:\\Users\\Synthetic\\Temp\\silansia-p94e1-aabbccddeeff1234';

    private const DATA = self::ROOT.'\\postgres-data';

    private const OWNER = 'silansia_c2_aabbccddeeff1234';

    private const PORT = '50123';

    public function test_c2_overrides_only_the_two_approved_source_pins(): void
    {
        $different = array_diff_assoc(BillingC2Baseline::sourcePins(), Identity::PINS);
        self::assertSame(array_intersect_key(BillingC2Baseline::APPROVED, Identity::PINS), $different);
        self::assertSame(['resources/js/components/Payments/PaymentPages.jsx', 'tests/Frontend/paymentComponents.test.mjs'], array_keys($different));
        self::assertSame(array_keys(Identity::PINS), array_keys(BillingC2Baseline::sourcePins()));
    }

    #[DataProvider('windowsCachePaths')]
    public function test_windows_cache_preflight_before_provisioning(string $case, ?string $reason): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Requires the Windows PHP CLI and its native path separator.');
        }

        $run = bin2hex(random_bytes(8));
        // Use a disposable fixture beside the test. A sandbox temp path can contain shell metacharacters.
        $root = realpath(__DIR__).'\\silansia-p94e1-'.$run;
        $temp = $root.'\\tmp';
        $cacheDirectory = $temp.'\\silansia-phase1-'.$run;
        $cache = $cacheDirectory.'\\config.php';
        $marker = $root.'\\owner.json';
        $ini = $root.'\\probe.ini';
        $ownerToken = str_repeat('a', 64);
        self::assertTrue(mkdir($root, 0700));
        try {
            self::assertTrue(mkdir($temp, 0700));
            self::assertTrue(mkdir($cacheDirectory, 0700));
            $configuration = BillingC2Preflight::cacheConfiguration($root, $run);
            $iniTemp = $case === 'forward_slash_temp' ? str_replace('\\', '/', $temp) : $temp;
            $configuredCache = match ($case) {
                'mixed_slash_cache' => $temp.'/silansia-phase1-'.$run.'\\config.php',
                'outside_root' => dirname($root).'\\config.php',
                'traversal' => $cacheDirectory.'\\..\\silansia-phase1-'.$run.'\\config.php',
                'alias' => $cache.'.',
                'wrong_invocation' => $temp.'\\silansia-phase1-0000000000000000\\config.php',
                default => $configuration['cache'],
            };
            file_put_contents($marker, json_encode(['root' => $root, 'token' => $ownerToken], JSON_THROW_ON_ERROR));
            file_put_contents($ini, ($case === 'forward_slash_temp' ? 'sys_temp_dir="'.$iniTemp.'"' : $configuration['ini']).PHP_EOL);
            if ($case === 'existing_cache') {
                file_put_contents($cache, 'synthetic cache');
            }

            $base = dirname(__DIR__, 2);
            $probe = new Process([PHP_BINARY, '-c', $ini], $base, [
                'PHP_INI_SCAN_DIR' => $temp, 'SILANSIA_P94E1_VERIFY' => '1', 'SILANSIA_RUNTIME_ROOT' => $root,
                'SILANSIA_RUNTIME_OWNER' => $ownerToken, 'SILANSIA_BILLING_C2_RUN' => $run, 'APP_CONFIG_CACHE' => $configuredCache,
                'SILANSIA_CACHE_PROBE_VALID' => $reason === null ? '1' : '0',
            ], <<<'PHP'
                <?php
                require 'vendor/autoload.php';
                use Tests\Support\BillingC2Cluster;
                use Tests\Support\BillingC2Preflight;
                use Tests\Support\PostgresTestGuard;

                $root = getenv('SILANSIA_RUNTIME_ROOT');
                $run = getenv('SILANSIA_BILLING_C2_RUN');
                $stage = 'initial';
                $error = null;
                try {
                    if (getenv('SILANSIA_CACHE_PROBE_VALID') === '1') {
                        $cache = BillingC2Preflight::assertCachePreconditions();
                        $guard = new PostgresTestGuard(['database' => 'silansia_test_'.$run, 'oid' => '123', 'owner' => 'synthetic',
                            'address' => '127.0.0.1', 'port' => '50123', 'host' => '127.0.0.1', 'connection_port' => '50123',
                            'token' => str_repeat('b', 64), 'cache' => $cache]);
                        $guard->assertCacheIsolated();
                        $stage = 'cache_verified';
                    } else {
                        // The fixture root is an empty bin. No PostgreSQL executable is available to this probe.
                        (new BillingC2Cluster($root, $root))->verify($stage);
                    }
                } catch (LogicException $failure) {
                    $error = $failure->getMessage();
                }
                echo json_encode(['temp' => sys_get_temp_dir(), 'stage' => $stage, 'error' => $error,
                    'data_exists' => file_exists($root.'\\postgres-data'), 'secret_exists' => file_exists($root.'\\initdb-password')], JSON_THROW_ON_ERROR);
                PHP, 15);
            $probe->mustRun();
            self::assertSame([
                'temp' => $iniTemp, 'stage' => $reason === null ? 'cache_verified' : 'cache_preconnect', 'error' => $reason,
                'data_exists' => false, 'secret_exists' => false,
            ], json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            foreach ([$cache, $marker, $ini] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([$cacheDirectory, $temp, $root] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }

    public static function windowsCachePaths(): iterable
    {
        yield 'native path passes the C2 and existing guards' => ['native', null];
        foreach (['forward_slash_temp', 'mixed_slash_cache', 'outside_root', 'traversal', 'alias', 'wrong_invocation'] as $case) {
            yield $case => [$case, 'billing_c2_cache_path_mismatch'];
        }
        yield 'existing cache' => ['existing_cache', 'billing_c2_framework_cache_present'];
    }

    public function test_control_data_preserves_the_full_decimal_identifier(): void
    {
        self::assertSame(['system_id' => '9999999999999999999', 'state' => 'shut down'], BillingC2Cluster::parseControl(
            "Database system identifier:           9999999999999999999\r\nDatabase cluster state:               shut down\r\n"
        ));
    }

    public function test_ambiguous_control_data_is_rejected(): void
    {
        $this->expectExceptionMessage('billing_c2_control_data_unverified');
        BillingC2Cluster::parseControl("Database system identifier: 12345\nDatabase system identifier: 67890\nDatabase cluster state: in production\n");
    }

    public function test_pid_file_must_bind_the_owned_directory_to_the_loopback_port(): void
    {
        $lines = ['4242', str_replace('\\', '/', self::DATA), '1780000000', self::PORT, '', '127.0.0.1'];
        self::assertSame(['pid' => '4242', 'started' => '1780000000'], BillingC2Cluster::parsePidFile($lines, self::DATA, self::PORT));

        $this->expectExceptionMessage('billing_c2_cluster_pidfile_mismatch');
        BillingC2Cluster::parsePidFile($lines, 'C:\\existing\\data', self::PORT);
    }

    public function test_only_the_expected_loopback_listener_is_allowed(): void
    {
        BillingC2Cluster::assertListenerFacts("\r\n TCP 127.0.0.1:50123 0.0.0.0:0 LISTENING 4242\r\n TCP 0.0.0.0:5432 0.0.0.0:0 LISTENING 600\r\n", self::PORT, '4242');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('wrongListeners')]
    public function test_listener_mismatches_are_rejected(string $output): void
    {
        $this->expectExceptionMessage('billing_c2_listener_identity_mismatch');
        BillingC2Cluster::assertListenerFacts($output, self::PORT, '4242');
    }

    public static function wrongListeners(): iterable
    {
        yield 'missing' => [''];
        yield 'foreign process' => [' TCP 127.0.0.1:50123 0.0.0.0:0 LISTENING 1234'];
        yield 'wildcard' => [' TCP 0.0.0.0:50123 0.0.0.0:0 LISTENING 4242'];
        yield 'an extra listener on the same port' => [" TCP 127.0.0.1:50123 0.0.0.0:0 LISTENING 4242\n TCP [::]:50123 [::]:0 LISTENING 1234"];
        yield 'the postmaster listens on an extra port' => [" TCP 127.0.0.1:50123 0.0.0.0:0 LISTENING 4242\n TCP 0.0.0.0:5432 0.0.0.0:0 LISTENING 4242"];
    }

    #[DataProvider('wrongClusters')]
    public function test_cluster_identity_mismatch_is_rejected(string $field, string $value): void
    {
        $expected = ['system_id' => '7442512345678901234', 'data' => self::DATA, 'port' => self::PORT, 'started' => '1780000000'];
        $facts = $expected + ['listen' => '127.0.0.1', 'sockets' => '', 'server_port' => self::PORT, 'host' => '127.0.0.1',
            'role' => self::OWNER, 'session_role' => self::OWNER];
        BillingC2Cluster::assertServerFacts($facts, $expected, self::OWNER);

        $this->expectExceptionMessage('billing_c2_cluster_identity_mismatch');
        BillingC2Cluster::assertServerFacts(array_replace($facts, [$field => $value]), $expected, self::OWNER);
    }

    public static function wrongClusters(): iterable
    {
        yield 'foreign system ID' => ['system_id', '7442512345678912345'];
        yield 'foreign data directory' => ['data', 'C:\\existing\\data'];
        yield 'wrong connection port' => ['server_port', '5432'];
        yield 'remote bind' => ['listen', '0.0.0.0'];
        yield 'remote peer' => ['host', '192.0.2.1'];
        yield 'added socket' => ['sockets', 'C:/socket'];
        yield 'role switch' => ['role', 'postgres'];
        yield 'foreign session role' => ['session_role', 'postgres'];
        yield 'older postmaster' => ['started', '1779999990'];
    }

    public function test_cluster_path_rejects_shell_metacharacters_and_aliases(): void
    {
        foreach (['C:\\Users\\%TEMP%', 'C:\\Users\\..\\postgres', 'C:\\Users\\demo&other', '\\\\host\\share'] as $path) {
            try {
                BillingC2Cluster::windowsPath($path);
                self::fail('Unsafe path accepted');
            } catch (LogicException $exception) {
                self::assertSame('billing_c2_windows_path_invalid', $exception->getMessage());
            }
        }
    }

    public function test_database_receipt_rejects_a_changed_oid(): void
    {
        $run = 'aabbccddeeff1234';
        $ownerToken = str_repeat('a', 64);
        $identity = ['database' => Identity::databaseFor($run), 'oid' => '30000', 'owner' => self::OWNER,
            'host' => '127.0.0.1', 'connection_port' => self::PORT, 'address' => '127.0.0.1/32', 'port' => self::PORT,
            'token' => str_repeat('b', 64), 'runtime_root' => self::ROOT];
        $receipt = Identity::receipt($run, $identity, $ownerToken);
        Identity::verifyReceipt($receipt, $run, $identity, $ownerToken);

        $this->expectExceptionMessage('receipt_mismatch');
        Identity::verifyReceipt($receipt, $run, array_replace($identity, ['oid' => '30001']), $ownerToken);
    }

    public function test_historical_database_is_denied_even_if_its_invocation_token_looks_valid(): void
    {
        $this->expectExceptionMessage('historical_target_denied');
        Identity::databaseFor('4c835a1f0c428df9');
    }
}
