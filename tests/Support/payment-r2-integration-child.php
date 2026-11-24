<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentR2IntegrationFixtures as Fixtures;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Tests\Support\PaymentR2IntegrationObserver as Observer;
use Tests\Support\PaymentR2IntegrationRuntime as Runtime;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    Identity::require(PHP_SAPI === 'cli' && count($argv) >= 2, 'child_cli_required');
    $action = $argv[1];
    Identity::require(count($argv) === match ($action) {
        'preflight', 'migrate', 'expire-context' => 2,
        'fixture', 'observe' => 3,
        'scan', 'stale', 'missing', 'scanner-denial' => 4,
        default => 0,
    }, 'invalid_child_action');
    $app = Runtime::boot($action !== 'preflight', ! in_array($action, ['preflight', 'migrate'], true));
    $result = match ($action) {
        'preflight' => ['preconnect' => true, 'cache' => sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'silansia-phase1-'.getenv('SILANSIA_05E_RUN').DIRECTORY_SEPARATOR.'config.php'],
        'migrate' => ['schema' => Runtime::migrate($app)],
        'fixture' => (new Fixtures)->prepare($argv[2]),
        'observe' => ['snapshot' => Observer::snapshot($argv[2])],
        'scan' => ['scan' => Fixtures::scan($argv[2], $argv[3])],
        'scanner-denial' => ['denial' => Fixtures::prepareScannerDenial($argv[2], $argv[3])],
        'expire-context' => ['token' => Fixtures::expireContext(stream_get_contents(STDIN, 12001))],
        'stale' => (static function () use ($argv): array {
            Fixtures::holdPreviousGeneration($argv[2], $argv[3]);

            return ['stale' => true];
        })(),
        'missing' => (static function () use ($argv): array {
            Fixtures::hideProof($argv[2], $argv[3]);

            return ['missing' => true];
        })(),
    };
    if ($action !== 'preflight') {
        DB::disconnect('silansia_testing');
    }
    echo json_encode(['ok' => true, ...$result], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo '{"ok":false}';
    exit(1);
}
