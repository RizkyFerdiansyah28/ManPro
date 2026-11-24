<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use PDO;

final class PaymentR2IntegrationIdentity
{
    public const HISTORICAL_DATABASE = 'silansia_test_4c835a1f0c428df9';

    public const PINS = [
        'resources/js/utils/payments/api.js' => '01fb3850d3b1502e877ae2c6636d548a2dea7120112291982ce7651ac396ff11',
        'resources/js/utils/payments/workflow.js' => '4fc2a4a6bfef4c6bd4e9c7e686ad48d6c27b9e65695c8688ae8fbafa0cb6a5e3',
        'resources/js/utils/payments/sessionBoundary.js' => 'bffaf74118e9e9bd9cfca42acb9fb9823ddb2a3febbc8dbd33e03923ac346c11',
        'resources/js/components/Payments/PaymentPages.jsx' => '1e53af018a8e74e372b817cb7c80f2a6be11bd0a306d8545707968eef41d4503',
        'tests/Frontend/paymentWorkflow.test.mjs' => '1c4ad927acfa685eb8a32149b5f7e3c74b2ec780e4c086eea71bc7adcc98d763',
        'tests/Frontend/paymentComponents.test.mjs' => 'f913d7b4d8a649ecfd1dceb41133ba4cd8e9c43382bfb58167e146fb3f75aa3b',
        'app/Http/Controllers/Billing/PaymentController.php' => 'df1fc152bd906dd68c0644863a7f59e85d4936ac9f8556c63b4441e45173ac81',
        'app/Services/Billing/PaymentService.php' => 'f2e7074475ce68a3b6b7928201ee82ed85f25edf452c0410a86c38be19f3151c',
        'app/Services/Billing/PaymentHttpReader.php' => '07039c2d39ce50acd1b5ffb97ec6c311047a76fe26ce7fe22be6ae6e2a1350ff',
        'app/Support/Billing/PaymentHttpResponse.php' => 'e8fa5048911e9bba184ce31e4682b934e8648b4de30484010cec95bb67558c46',
        'app/Support/Billing/PaymentCommandContext.php' => 'd676b34cfc813b910e1b0698af55c873eb0ba733c5e354f4d749f6012ccc0bd7',
        'app/Support/Billing/PaymentUiContext.php' => 'ba26c5bf64dbeef65d6cf8558bd01181fd83730fd0ca0452f364bfc08e5dff0f',
        'app/Services/Billing/ProtectedPaymentProof.php' => '8a4170fecf18a83f5af0ddf7eee5d4658d94d56035b91080f9f9c3348d8d21c8',
        'app/Services/Billing/PaymentProofUse.php' => '4af8cd1980e31d10d0aa0a7faa87900e4fcb7cc92a7193c1431e8c056d39fd0f',
        'app/Services/Billing/ProofScanReadiness.php' => '53e58c7d639fd55c67be650ac91538aca7ff20821b27442aa8068834ff09f40b',
        'app/Services/Billing/ProofScanSchemaContract.php' => 'b43d26455b3a638481bdc6953db38f71d0455cc581efe351f4e67e60c1743c39',
        'app/Services/Billing/AcademicProofScanner.php' => '1f44652f5d74e6a0be9d2929dbd62ce7a97fcd346c84150eb99259507693c6be',
        'app/Services/Billing/AcademicProofScanWorker.php' => '65c63251c153c326ea1b28638d0a1c9db3bcd4ac3583de642d2cd306e9340ffe',
        'app/Console/Commands/RunAcademicProofScan.php' => 'e4ee6dc3cecb1948ad04c8205b86861b6f154f34f9c302494db2a45089f148b5',
        'database/migrations/2026_10_21_000002_create_silansia_foundation.php' => '4df5aa64b88be56d9b84dd7d79c1848a0b35eb20dd818c4706959d9a113c82a8',
        'database/migrations/2026_10_21_000003_reject_nan_monetary_values.php' => '012c910c07981082e9978a9ae9e2d4c891837fa5f3e02ce6ec2f3f3960d12255',
        'database/migrations/2026_11_12_000008_expand_billing_schema.php' => '2cd7bf77669a724ce660e0a7277d1a2d26b32048e40af8ec5de8377617d19db3',
        'database/migrations/2026_11_13_000009_add_billing_integrity_guards.php' => '8c29cdf61723cefedfd47a3d468cd28059206b285db8289be538e81b4a80d6c0',
        'database/migrations/2026_11_18_000010_create_proof_scan_technical_schema.php' => '6fa2b3ce9de9e8922ca4b9fb3c0ef2321da34cc02b9cfd7e407faf2335e21b43',
        'database/migrations/2026_11_18_000011_add_proof_scan_completeness_guards.php' => '3e5811552ac70106b98a252d15b5cc2faefd1b6117b838191c16a50c37abe3ab',
        'tests/Support/run-framework-isolated.php' => 'e4c7ea92edc9ee726084ba74aae94d309972a89603f67d19e7576a4e009c0e30',
        'tests/Support/run-proof-scan-runtime.php' => '684a4920e73b7e791fd6bb854d8782a1e0ca10ea77133166ff9bd0e461d7acb3',
        'tests/Support/run-postgres.php' => '00d90eb49d8e1135c73e487071604246cf7d9c6523d25bd5bc41a717e39a8718',
        'tests/Support/PostgresTestGuard.php' => 'e3659dacb2e86e5dde46036cfee7e6ada613a1d2214db6d68a84c9308d235d36',
        'tests/Browser/PaymentBrowserIntegrationTest.php' => '4dd3459d388cabbf4e890e90260fdd91f669e81bc054cff926df5c5abcfa5068',
        'tests/Browser/run-payment-laravel.mjs' => '0c67bba2c1493f458c68bf1c1b058f5c0c9ff8adf5662d521f233796344a9bf0',
    ];

    public static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new LogicException($reason);
        }
    }

    public static function databaseFor(string $run): string
    {
        self::require(preg_match('/\A[a-f0-9]{16}\z/', $run) === 1, 'invalid_run_id');
        $name = 'silansia_test_'.$run;
        self::require($name !== self::HISTORICAL_DATABASE, 'historical_target_denied');

        return $name;
    }

    public static function pins(string $base, array $pins = self::PINS): void
    {
        foreach ($pins as $path => $hash) {
            $file = $base.'/'.$path;
            self::require(is_file($file) && ! is_link($file) && hash_equals($hash, hash_file('sha256', $file)), 'source_pin_mismatch');
        }
    }

    public static function read(string $path): array
    {
        FrameworkRuntimeIsolation::path($path);
        self::require(is_file($path) && ! is_link($path) && filesize($path) <= 2_097_152, 'invalid_owned_record');
        $result = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::require(is_array($result), 'invalid_owned_record');

        return $result;
    }

    public static function write(string $path, array $value): void
    {
        FrameworkRuntimeIsolation::path($path);
        self::writeNew($path, json_encode($value, JSON_THROW_ON_ERROR));
    }

    public static function writeNew(string $path, string $bytes): void
    {
        $file = fopen($path, 'xb');
        self::require(is_resource($file), 'owned_record_exists');
        try {
            self::require(fwrite($file, $bytes) === strlen($bytes) && fflush($file), 'owned_record_write_failed');
        } finally {
            fclose($file);
        }
    }

    public static function receipt(string $run, array $identity, string $ownerToken): array
    {
        self::require($identity['database'] === self::databaseFor($run)
            && ctype_digit($identity['oid'])
            && preg_match('/\A[a-f0-9]{64}\z/', $identity['token']) === 1
            && preg_match('/\A[a-f0-9]{64}\z/', $ownerToken) === 1, 'invalid_receipt_identity');
        $parts = ['invocation' => $run, 'name' => $identity['database'], 'oid' => $identity['oid'],
            'owner' => $identity['owner'], 'host' => $identity['host'], 'port' => $identity['connection_port'],
            'address' => $identity['address'], 'server_port' => $identity['port'],
            'marker_digest' => hash('sha256', 'silansia-phase1:'.$identity['token']),
            'runtime_root' => $identity['runtime_root']];
        $parts['digest'] = hash_hmac('sha256', json_encode(
            $parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ), $ownerToken);

        return $parts;
    }

    public static function verifyReceipt(array $received, string $run, array $identity, string $ownerToken): void
    {
        self::require($received === self::receipt($run, $identity, $ownerToken), 'receipt_mismatch');
    }

    public static function neutral(string $host, string $port, string $owner, #[\SensitiveParameter] string $password): PDO
    {
        self::require($host === '127.0.0.1' && preg_match('/\A[1-9][0-9]{0,4}\z/', $port) === 1 && (int) $port <= 65535
            && preg_match('/\A[A-Za-z_][A-Za-z_0-9]{0,62}\z/', $owner) === 1 && $password !== '', 'explicit_neutral_config_required');
        // Explicit libpq connection parameters. No Laravel config or .env is loaded by the parent.
        foreach (['PGSERVICE', 'PGSERVICEFILE', 'PGOPTIONS', 'PGHOSTADDR'] as $key) {
            self::require(getenv($key) === false, 'libpq_override_denied');
        }
        $pdo = new PDO("pgsql:host={$host};hostaddr={$host};port={$port};dbname=postgres;sslmode=disable;connect_timeout=5",
            $owner, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false]);
        $pdo->exec("SET statement_timeout='10s'");
        $pdo->exec("SET lock_timeout='5s'");
        self::assertNeutral($pdo, $host, $port, $owner);

        return $pdo;
    }

    public static function assertNeutral(PDO $pdo, string $host, string $port, string $owner): void
    {
        $row = $pdo->query("SELECT current_database() AS db,current_user AS role,session_user AS session_role,
            host(inet_server_addr()) AS address,inet_server_port()::text AS port,current_setting('server_version_num')::int AS version,
            pg_get_userbyid(d.datdba) AS owner,(r.rolcreatedb OR r.rolsuper) AS can_create
            FROM pg_database d JOIN pg_roles r ON r.rolname=current_user WHERE d.datname=current_database()")->fetch(PDO::FETCH_ASSOC);
        self::require(is_array($row) && $row['db'] === 'postgres' && $row['role'] === $owner
            && $row['session_role'] === $owner && $row['owner'] === $owner && $row['address'] === $host
            && $row['port'] === $port && $row['version'] >= 180000 && $row['version'] < 190000
            && $row['can_create'] === true, 'neutral_identity_mismatch');
    }

    public static function catalog(PDO $pdo, string $name): array|false
    {
        $query = $pdo->prepare("SELECT oid::text AS oid,pg_get_userbyid(datdba) AS owner,
            shobj_description(oid,'pg_database') AS marker FROM pg_database WHERE datname=?");
        $query->execute([$name]);

        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public static function verifyCatalog(PDO $pdo, array $identity): void
    {
        $row = self::catalog($pdo, $identity['database']);
        self::require(is_array($row) && $row['oid'] === $identity['oid'] && $row['owner'] === $identity['owner']
            && $row['marker'] === 'silansia-phase1:'.$identity['token'], 'owned_catalog_mismatch');
    }
}
