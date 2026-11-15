<?php

namespace Tests\Browser;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceIssuanceFeatureTestCase;
use Tests\Support\PostgresTestGuard;

#[Group('invoice-http')]
class InvoiceBrowserIntegrationTest extends InvoiceIssuanceFeatureTestCase
{
    public function test_real_browser_laravel_owned_postgres_integration(): void
    {
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe($this->app, DB::connection());
        $this->assertSame('1', getenv('SILANSIA_P93D_VERIFY'));
        $password = 'P93d!'.bin2hex(random_bytes(16));
        $sources = [];
        foreach (['happy', 'lost', 'uncommitted', 'zero', 'null_price', 'no_service', 'mixed', 'future', 'inactive_history',
            'tamper', 'token', 'csrf', 'session', 'audience', 'malformed', 'concurrent'] as $name) {
            $changes = match ($name) {
                'zero' => ['price' => '0.00'], 'null_price' => ['price' => null],
                'no_service' => ['no_service' => true], 'inactive_history' => ['active' => false], default => [],
            };
            $s = $this->source($changes);
            if ($name === 'mixed') {
                $this->sourceTransfer($s, '2026-10-15 00:00:00+07');
            }
            $residentName = 'P93D '.$name.' '.substr((string) Str::uuid(), 0, 8);
            $s['elderly']->update(['name' => $residentName]);
            foreach (['admin', 'family', 'next'] as $role) {
                $s[$role]->update(['name' => 'Synthetic P9-3D '.$role, 'email' => 'p93d-'.Str::uuid().'@example.test', 'password' => $password]);
            }
            $sources[$name] = ['resident' => $s['elderly']->id, 'name' => $residentName, 'owner' => $s['family']->id,
                'month' => $name === 'future' ? DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'Asia/Jakarta','YYYY-MM-01')") : '2026-10-01',
                'admin' => $s['admin']->only('id', 'email'), 'family' => $s['family']->only('id', 'email')];
        }
        $accounts = [];
        foreach (['caregiver' => ['perawat', true], 'inactive' => ['admin', false], 'other_admin' => ['admin', true]] as $name => [$role, $active]) {
            $user = $this->account($role, $active, ['name' => 'Synthetic P9-3D '.$name,
                'email' => 'p93d-'.Str::uuid().'@example.test', 'password' => $password]);
            // Existing account() deliberately sets its own password last.
            $user->update(['password' => $password]);
            $this->assertTrue(app('hash')->check($password, $user->getAuthPassword()));
            $accounts[$name] = $user->only('id', 'email');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        $this->assertIsResource($socket);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->assertNotContains($port, [8000, 5187]);
        $origin = 'http://127.0.0.1:'.$port;
        $run = substr($guard->identity['database'], strlen('silansia_test_'));
        $directory = dirname($guard->identity['cache']);
        $views = $directory.DIRECTORY_SEPARATOR.'p93d-views';
        $this->assertTrue(mkdir($views, 0700));
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => $origin,
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE' => $guard->identity['cache'],
            'APP_ROUTES_CACHE' => $directory.'/p93d-routes.php', 'APP_EVENTS_CACHE' => $directory.'/p93d-events.php',
            'DB_CONNECTION' => 'silansia_testing', 'DB_DATABASE' => 'postgres', 'DB_URL' => '',
            'SILANSIA_P93D_VERIFY' => '1', 'SILANSIA_TEST_IDENTITY' => json_encode($guard->identity, JSON_THROW_ON_ERROR),
            'SILANSIA_TEST_DB_DATABASE' => $guard->identity['database'], 'SILANSIA_TEST_DB_HOST' => $db['host'],
            'SILANSIA_TEST_DB_PORT' => (string) $db['port'], 'SILANSIA_TEST_DB_USERNAME' => $db['username'],
            'SILANSIA_TEST_DB_PASSWORD' => $db['password'], 'SILANSIA_TEST_DB_SSLMODE' => $db['sslmode'] ?? 'prefer',
            'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'silansia_p93d_'.$run, 'SESSION_SECURE_COOKIE' => 'false',
            'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
            'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'true', 'FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE' => 'owned-p93d-browser-only',
            'INVOICE_ISSUANCE_ENABLED' => 'true', 'VIEW_COMPILED_PATH' => $views, 'BCRYPT_ROUNDS' => '4',
            'P93D_PHP_BINARY' => PHP_BINARY, 'P93D_EVIDENCE_RUN' => $run];
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', 'public', 'tests/Support/p93d-server.php'], base_path(), $environment);
        $server->setTimeout(null);
        $server->start();
        try {
            $proof = null;
            for ($i = 0; $i < 60; $i++) {
                $data = @file_get_contents($origin.'/__p93d/identity', false, stream_context_create(['http' => ['timeout' => 1]]));
                if ($data !== false) {
                    $proof = json_decode($data, true);
                    break;
                }
                if (! $server->isRunning()) {
                    break;
                }
                usleep(100000);
            }
            $this->assertIsArray($proof, 'Server test tidak siap; detail koneksi tidak dicetak.');
            $this->assertSame('testing', $proof['environment'] ?? null);
            $this->assertSame('silansia_testing', $proof['connection'] ?? null);
            $this->assertSame($guard->identity['database'], $proof['database'] ?? null);
            foreach (['config_cached', 'environment_file_exists', 'csrf_test_bypass'] as $field) {
                $this->assertFalse($proof[$field] ?? true, $field);
            }
            $this->assertTrue($proof['ownership_verified'] ?? false);
            file_put_contents(base_path('docs/report/SILANSIA_P9_3D_ISOLATION_'.$run.'.json'), json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $guard->assertTarget(DB::connection());
            $browser = new Process(['node', 'tests/Browser/run-billing-laravel.mjs'], base_path(), $environment);
            $browser->setInput(json_encode(compact('origin', 'sources', 'accounts', 'password'), JSON_THROW_ON_ERROR));
            $browser->setTimeout(240);
            $browser->run();
            $result = json_decode(trim($browser->getOutput()), true);
            $this->assertSame(0, $browser->getExitCode(), 'Browser integration gagal; lihat evidence aman P9-3D.');
            $this->assertIsArray($result);
            $this->assertTrue($result['ok'] ?? false);
            $this->assertGreaterThanOrEqual(10, $result['passed'] ?? 0);
            $guard->assertTarget(DB::connection());
            echo 'P93D_BROWSER '.json_encode(['database' => $guard->identity['database'], 'passed' => $result['passed'], 'failed' => $result['failed']]).PHP_EOL;
        } finally {
            $server->stop(2);
            // Only exact compiled view files in this runner's newly created directory.
            foreach (glob($views.'/*.php') as $file) {
                unlink($file);
            }
            rmdir($views);
        }
    }
}
