<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Billing\InvoiceSchemaContract;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\Billing\ProofScanSchemaContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

final class PaymentR2IntegrationRuntime
{
    private const MIGRATIONS = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
        '2026_10_21_000001_convert_user_keys_to_uuid',
        '2026_10_21_000002_create_silansia_foundation',
        '2026_10_21_000003_reject_nan_monetary_values',
        '2026_10_24_000004_create_camera_operating_periods',
        '2026_10_24_000005_link_ai_detections_to_camera_periods',
        '2026_11_10_000006_add_incident_actions_and_precision',
        '2026_11_10_000007_harden_staff_notifications',
        '2026_11_12_000008_expand_billing_schema',
        '2026_11_13_000009_add_billing_integrity_guards',
        '2026_11_18_000010_create_proof_scan_technical_schema',
        '2026_11_18_000011_add_proof_scan_completeness_guards',
    ];

    public static function validate(bool $owned): ?PostgresTestGuard
    {
        Runtime::environment();
        $root = Runtime::root();
        $run = getenv('SILANSIA_05E_RUN') ?: '';
        $name = Identity::databaseFor($run);
        $key = getenv('APP_KEY') ?: '';
        Identity::require($root === dirname($root).DIRECTORY_SEPARATOR.'silansia-p94e1-'.$run
            && getenv('SILANSIA_05E_STAGE') === 'authorized-05e2' && getenv('APP_ENV') === 'testing'
            && getenv('DB_CONNECTION') === 'silansia_testing' && getenv('DB_URL') === ''
            && getenv('DB_DATABASE') === '' && getenv('APP_PREVIOUS_KEYS') === ''
            && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/', $key) === 1
            && ! file_exists(getenv('APP_CONFIG_CACHE')) && ! is_link(getenv('APP_CONFIG_CACHE')), 'isolated_child_environment_required');
        Runtime::assertProtected();
        if (! $owned) {
            Identity::require(getenv('SILANSIA_TEST_IDENTITY') === false && getenv('SILANSIA_TEST_DB_DATABASE') === ''
                && getenv('SILANSIA_TEST_DB_PASSWORD') === '', 'database_before_preflight');

            return null;
        }
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertCacheIsolated();
        Identity::require($guard->identity['runtime_root'] === $root && $guard->identity['database'] === $name
            && getenv('SILANSIA_TEST_DB_DATABASE') === $name && getenv('SILANSIA_TEST_DB_HOST') === '127.0.0.1'
            && getenv('SILANSIA_TEST_DB_PORT') === $guard->identity['connection_port']
            && getenv('SILANSIA_TEST_DB_USERNAME') === $guard->identity['owner']
            && getenv('SILANSIA_TEST_DB_SSLMODE') === 'disable' && (getenv('SILANSIA_TEST_DB_PASSWORD') ?: '') !== '', 'exact_owned_environment_required');
        Identity::verifyReceipt(Identity::read($root.'/database-receipt.json'), $run, $guard->identity, getenv('SILANSIA_RUNTIME_OWNER'));

        return $guard;
    }

    public static function boot(bool $owned = true, bool $payments = true): Application
    {
        $guard = self::validate($owned);
        $base = dirname(__DIR__, 2);
        $root = Runtime::root();
        $app = require $base.'/bootstrap/app.php';
        $app->useEnvironmentPath(Runtime::path($root.'/environment', true));
        $app->loadEnvironmentFrom('05e-absent.env');
        foreach (['05e-absent.env', '05e-absent.env.testing'] as $absent) {
            $path = $root.'/environment/'.$absent;
            Identity::require(! file_exists($path) && ! is_link($path), 'owned_dotenv_must_be_absent');
        }
        $app->usePublicPath(Runtime::path($root.'/public', true));
        Runtime::attach($app);
        // No connector may open a connection during bootstrap, including a blank DB name.
        $app->resolving('db', static function ($manager) use ($owned): void {
            if (! $owned) {
                $manager->extend('pgsql', static fn () => throw new \LogicException('database_before_ownership'));
            }
        });
        $app->make(Kernel::class)->bootstrap();
        Runtime::resolved($app);
        Identity::require($app['db']->getConnections() === [] && $app->environment('testing')
            && ! $app->configurationIsCached() && config('database.default') === 'silansia_testing'
            && config('app.key') === getenv('APP_KEY') && empty(config('app.previous_keys'))
            && config('database.connections.silansia_testing.database') === ($guard?->identity['database'] ?? '')
            && config('database.connections.silansia_testing.url') === null, 'resolved_child_environment_mismatch');
        $hot = Runtime::path($root.'/public/05e-hot-absent');
        Identity::require(! file_exists($hot) && ! is_link($hot), 'vite_hot_file_present');
        $app->make(Vite::class)->useHotFile($hot);
        if ($guard !== null) {
            self::checkDatabase($app, DB::connection(), $guard);
            if ($payments) {
                config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-05e-simulation',
                    'invoice_issuance.enabled' => true, 'payment_core.enabled' => true, 'payment_http.enabled' => true,
                    'payment_http.synthetic_validation' => true]);
                Runtime::path(app(PaymentProofEnvironment::class)->root());
            }
        }

        return $app;
    }

    public static function checkDatabase(Application $app, Connection $db, ?PostgresTestGuard $guard = null): void
    {
        $guard ??= self::validate(true);
        $guard->assertSafe($app, $db);
        $db->statement("SET statement_timeout='20s'");
        $db->statement("SET lock_timeout='5s'");
        $db->statement("SET idle_in_transaction_session_timeout='20s'");
        $role = $db->selectOne("SELECT session_user::text AS role,current_setting('server_version_num')::int AS version,
            current_setting('session_replication_role') AS replication");
        Identity::require($role->role === $guard->identity['owner'] && $role->version >= 180000 && $role->version < 190000
            && $role->replication === 'origin', 'database_role_or_version_mismatch');
    }

    public static function migrate(Application $app, ?PostgresTestGuard $c3Guard = null): array
    {
        $base = dirname(__DIR__, 2);
        if ($c3Guard === null) {
            Identity::pins($base);
        }
        $files = glob($base.'/database/migrations/*.php');
        $expected = array_map(static fn (string $file) => $base.'/database/migrations/'.$file.'.php', self::MIGRATIONS);
        Identity::require(array_map(static fn ($p) => str_replace('\\', '/', $p), $files) === array_map(
            static fn ($p) => str_replace('\\', '/', $p), $expected), 'migration_source_order_mismatch');
        Identity::require(DB::scalar("SELECT to_regclass('public.migrations')") === null, 'fresh_owned_database_required');
        $groups = [array_slice($expected, 0, -2), [$expected[count($expected) - 2]], [$expected[count($expected) - 1]]];
        $financial = $app->make(InvoiceSchemaContract::class);
        $technical = $app->make(ProofScanSchemaContract::class);
        foreach ($groups as $index => $paths) {
            self::checkDatabase($app, DB::connection(), $c3Guard);
            Identity::require((int) DB::scalar('SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid()') === 0,
                'migration_peers_present');
            Identity::require($app->make(Kernel::class)->call('migrate', ['--database' => 'silansia_testing',
                '--path' => $paths, '--realpath' => true, '--force' => true, '--no-interaction' => true], new BufferedOutput) === 0,
                'owned_migration_failed');
            $applied = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
            Identity::require($applied === array_slice(self::MIGRATIONS, 0, count(self::MIGRATIONS) - 2 + $index), 'migration_records_mismatch');
            $financial->assertReady();
            if ($index > 0) {
                $technical->prepareExpected();
                $technical->assertReady($index === 2);
            }
        }
        $deferred = DB::selectOne("SELECT tgdeferrable,tginitdeferred,tgenabled FROM pg_trigger
            WHERE tgrelid='public.payments'::regclass AND tgname='proof_scan_payment_initial_final' AND NOT tgisinternal");
        Identity::require($deferred?->tgdeferrable === true && $deferred->tginitdeferred === true && $deferred->tgenabled === 'O',
            'deferred_completeness_missing');

        return ['migrations' => $applied, 'financial_sha256' => $financial->fingerprint(), 'technical_contract' => true, 'm11_deferred' => true];
    }
}
