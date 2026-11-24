<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Throwable;

final class BillingC3Admission
{
    public const RUNTIME_CHECKS = ['C3-01', 'C3-02', 'C3-03', 'C3-04', 'C3-05', 'C3-06', 'C3-07', 'C3-08', 'C3-09', 'C3-10', 'C3-11'];

    public const TARGETS = BillingC3Bootstrap::TARGETS;

    public static function entry(array $args): int
    {
        try {
            $command = self::command($args);
            $windowsHelpers = BillingC3WindowsHelpers::fromHost();
            BillingC3Bootstrap::policyDigest();
            if ($command === 'control') {
                BillingC3Runtime::control($args[1], array_slice($args, 2), $windowsHelpers);

                return 0;
            }
            $digest = $command === 'parent' ? $args[4] : getenv('SILANSIA_BILLING_C3_MANIFEST');
            Identity::require(is_string($digest), 'billing_c3_manifest_required');
            $manifest = self::verifyManifest($digest);
            self::assertTool('php', PHP_BINARY, $manifest);
            Identity::require(PHP_OS_FAMILY === 'Windows' && extension_loaded('pdo_pgsql') && extension_loaded('gd'), 'billing_c3_php_runtime_required');
            $bin = BillingC2Cluster::windowsPath($args[$command === 'parent' ? 2 : 1]);
            foreach (['initdb', 'pg_ctl', 'pg_controldata', 'postgres'] as $tool) {
                self::assertTool($tool, $bin.'\\'.$tool.'.exe', $manifest);
            }
            self::assertTool('node', $manifest['tools']['node']['path'], $manifest);
            self::assertTool('chrome', $manifest['tools']['chrome']['path'], $manifest);
            self::assertFrontendDependencies(dirname(__DIR__, 2));
            if ($command === 'child') {
                return BillingC2Preflight::entry([$args[0], $bin], $digest, $windowsHelpers);
            }

            BillingC3ProcessJob::markReady();
            return BillingC2Preflight::entry([$args[0], 'e2e', $bin], $digest, $windowsHelpers);
        } catch (Throwable $error) {
            $reason = $error instanceof LogicException && preg_match('/\Abilling_c3_[a-z0-9_]{1,70}\z/', $error->getMessage())
                ? $error->getMessage() : 'billing_c3_admission_failed';
            echo json_encode(['status' => 'BLOCKED', 'reason' => $reason], JSON_THROW_ON_ERROR).PHP_EOL;

            return 78;
        }
    }

    public static function command(array $args): string
    {
        return BillingC3Bootstrap::command($args);
    }

    public static function validAction(array $args): bool
    {
        return BillingC3Bootstrap::validAction($args);
    }

    public static function verifyManifest(string $digest): array
    {
        $base = dirname(__DIR__, 2);
        $path = $base.'/tests/Support/billing-c3-source-manifest.json';
        Runtime::canonical($path);
        Identity::require(preg_match('/\A[a-f0-9]{64}\z/', $digest) === 1 && is_file($path) && filesize($path) < 1_000_000
            && hash_equals($digest, hash_file('sha256', $path)), 'billing_c3_manifest_not_approved');
        $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        Identity::require(($manifest['version'] ?? null) === 1 && is_array($manifest['files'] ?? null)
            && array_keys($manifest['tools'] ?? []) === ['php', 'node', 'chrome', 'initdb', 'pg_ctl', 'pg_controldata', 'postgres'], 'billing_c3_manifest_invalid');
        $paths = self::sourcePaths($base);
        Identity::require(array_keys($manifest['files']) === $paths, 'billing_c3_source_set_mismatch');
        foreach ($paths as $file) {
            Identity::require(is_string($manifest['files'][$file]) && preg_match('/\A[a-f0-9]{64}\z/', $manifest['files'][$file]) === 1,
                'billing_c3_manifest_invalid');
        }
        Identity::pins($base, $manifest['files']);

        return $manifest;
    }

    public static function sourcePaths(string $base): array
    {
        return BillingC3Bootstrap::sourcePaths($base);
    }

    public static function assertTool(string $tool, string $path, array $manifest): void
    {
        $path = BillingC2Cluster::windowsPath($path);
        $expected = $manifest['tools'][$tool] ?? [];
        Identity::require(isset($expected['path'], $expected['sha256']) && strcasecmp($path, $expected['path']) === 0
            && is_file($path) && hash_equals($expected['sha256'], hash_file('sha256', $path)), 'billing_c3_tool_identity_mismatch');
    }

    private static function assertFrontendDependencies(string $base): void
    {
        $lock = json_decode(file_get_contents($base.'/package-lock.json'), true, 128, JSON_THROW_ON_ERROR);
        foreach (['vite', 'playwright', '@vitejs/plugin-react', '@tailwindcss/vite'] as $name) {
            $relative = 'node_modules/'.$name;
            $path = $base.'/'.$relative.'/package.json';
            Runtime::canonical($path);
            Identity::require(is_file($path), 'billing_c3_frontend_dependency_missing');
            $installed = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            Identity::require(isset($lock['packages'][$relative]['version']) && ($installed['version'] ?? null) === $lock['packages'][$relative]['version'],
                'billing_c3_frontend_dependency_mismatch');
        }
    }
}
