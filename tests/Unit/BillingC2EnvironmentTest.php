<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC2Preflight;

final class BillingC2EnvironmentTest extends TestCase
{
    private const ROOT = 'C:\\Synthetic\\silansia-p94e1-aabbccddeeff1234';

    private const CHECK_CHILD = <<<'PHP'
        require 'vendor/autoload.php';
        try {
            Tests\Support\BillingC2Preflight::assertChildSettings();
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        } catch (LogicException $error) {
            $diagnostic = Tests\Support\BillingC2Preflight::childFailureDetails([
                'status' => 'BLOCKED', 'stage' => 'child_environment', 'reason' => $error->getMessage(),
            ]);
            echo json_encode(['ok' => false, ...$diagnostic], JSON_THROW_ON_ERROR);
        }
        PHP;

    public function test_native_proc_open_distinguishes_empty_from_missing(): void
    {
        $result = $this->probe(BillingC2Preflight::restrictedEnvironment([
            'C2_ARRAY_EMPTY' => '', 'C2_EXPLICIT_EMPTY=', 'C2_FIXED' => 'synthetic',
        ]), <<<'PHP'
            foreach (['C2_ARRAY_EMPTY', 'C2_ABSENT', 'C2_EXPLICIT_EMPTY', 'C2_FIXED'] as $name) {
                $value = getenv($name);
                $result[$name] = $value === false ? 'absent' : ($value === '' ? 'empty' : 'present');
            }
            echo json_encode($result, JSON_THROW_ON_ERROR);
            PHP);

        self::assertSame(['C2_ARRAY_EMPTY' => 'absent', 'C2_ABSENT' => 'absent', 'C2_EXPLICIT_EMPTY' => 'empty',
            'C2_FIXED' => 'present'], $result);
    }

    public function test_native_child_accepts_production_settings_without_inheriting_parent_overrides(): void
    {
        $seeded = ['APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'DB_USERNAME', 'DB_PASSWORD', 'DATABASE_URL',
            'SILANSIA_TEST_DB_DATABASE', 'SILANSIA_TEST_DB_PASSWORD', 'SILANSIA_TEST_IDENTITY', 'APP_PREVIOUS_KEYS', 'PGSERVICE'];
        $original = [];
        try {
            foreach ($seeded as $key) {
                $original[$key] = getenv($key);
                self::assertTrue(putenv($key.'=synthetic_parent_override'));
            }
            self::assertSame(['ok' => true], $this->probe(self::environment(), self::CHECK_CHILD));
        } finally {
            foreach ($original as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
    }

    #[DataProvider('invalidChildSettings')]
    public function test_native_child_rejects_invalid_settings(array $overrides, array $omit, string $reason): void
    {
        $env = array_replace(array_diff_key(self::environment(), array_flip($omit)), $overrides);
        self::assertSame(['ok' => false, 'child_stage' => 'child_environment', 'child_reason' => $reason],
            $this->probe($env, self::CHECK_CHILD));
    }

    public static function invalidChildSettings(): iterable
    {
        $required = 'billing_c2_child_environment_mismatch';
        yield 'absent driver' => [[], ['DB_CONNECTION'], $required];
        yield 'empty array driver becomes absent' => [['DB_CONNECTION' => ''], [], $required];
        yield 'explicit empty driver' => [['DB_CONNECTION='], ['DB_CONNECTION'], $required];
        yield 'development driver' => [['DB_CONNECTION' => 'pgsql'], [], $required];
        yield 'invalid environment' => [['APP_ENV' => 'local'], [], $required];
        yield 'missing generated app key' => [[], ['APP_KEY'], $required];
        yield 'invalid app key' => [['APP_KEY' => 'synthetic-malformed-key'], [], $required];
        yield 'financial gate override' => [['PAYMENT_CORE_ENABLED' => 'true'], [], $required];

        $external = 'billing_c2_external_database_environment';
        yield 'development database' => [['DB_DATABASE' => 'manpro_db'], [], $external];
        yield 'external URL' => [['DB_URL' => 'pgsql://fixture.invalid/fixture'], [], $external];
        yield 'empty database is still present' => [['DB_DATABASE='], [], $external];
        yield 'empty URL is still present' => [['DB_URL='], [], $external];
        yield 'lowercase host override' => [['db_host' => 'fixture.invalid'], [], $external];
        yield 'test database override' => [['SILANSIA_TEST_DB_DATABASE' => 'synthetic'], [], $external];
        yield 'test password override' => [['SILANSIA_TEST_DB_PASSWORD' => 'synthetic'], [], $external];
        yield 'legacy service variable' => [['SILANSIA_05E_DB_PORT' => '5432'], [], $external];
        yield 'existing identity' => [['SILANSIA_TEST_IDENTITY' => 'synthetic'], [], $external];
        yield 'alternate DSN variable' => [['DATABASE_URL' => 'synthetic'], [], $external];

        yield 'previous app keys' => [['APP_PREVIOUS_KEYS' => 'synthetic'], [], 'billing_c2_previous_keys_present'];
        yield 'explicit empty previous app keys' => [['APP_PREVIOUS_KEYS='], [], 'billing_c2_previous_keys_present'];
        yield 'libpq host address' => [['PGHOSTADDR' => '192.0.2.1'], [], 'billing_c2_inherited_postgres_environment'];
        yield 'empty libpq service' => [['PGSERVICE='], [], 'billing_c2_inherited_postgres_environment'];
        yield 'libpq options' => [['pgoptions' => 'synthetic'], [], 'billing_c2_inherited_postgres_environment'];
    }

    public function test_child_diagnostics_only_expose_known_stages_and_codes(): void
    {
        self::assertSame(['child_stage' => 'baseline', 'child_reason' => 'source_pin_mismatch'],
            BillingC2Preflight::childFailureDetails(['status' => 'BLOCKED', 'stage' => 'baseline', 'reason' => 'source_pin_mismatch',
                'root' => 'synthetic-private-root', 'token' => 'synthetic-private-token']));
        self::assertSame(['child_stage' => null, 'child_reason' => null],
            BillingC2Preflight::childFailureDetails(['status' => 'BLOCKED', 'stage' => 'unknown', 'reason' => 'billing_c2_unapproved']));
        self::assertSame(['child_stage' => null, 'child_reason' => null],
            BillingC2Preflight::childFailureDetails(['ok' => true, 'stage' => 'baseline', 'reason' => 'source_pin_mismatch']));
    }

    private static function environment(): array
    {
        return BillingC2Preflight::childProcessEnvironment(self::ROOT, 'aabbccddeeff1234', str_repeat('a', 64), self::ROOT.'\\ini');
    }

    private function probe(array $environment, string $code): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Requires the native Windows PHP process transport.');
        }

        // Start a PHP-only probe with an explicit environment and no launcher or database bootstrap.
        $child = proc_open([PHP_BINARY, '-n', '-r', $code], [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes, dirname(__DIR__, 2), $environment, ['bypass_shell' => true, 'create_process_group' => true]);
        self::assertIsResource($child);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($child));

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
