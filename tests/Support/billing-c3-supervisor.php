<?php

declare(strict_types=1);

use Tests\Support\BillingC3Bootstrap;
use Tests\Support\BillingC3ProcessJob as Job;

require __DIR__.'/BillingC3ProcessJob.php';

try {
    $token = getenv('SILANSIA_BILLING_C3_JOB') ?: '';
    if ($argc === 3 && in_array($argv[1], ['--probe', '--revoke', '--fail'], true) && preg_match('/\A[1-9][0-9]{0,9}\z/', $argv[2])) {
        $job = Job::open();
        $job->process((int) $argv[2]);
        if ($argv[1] === '--probe') {
            Job::assertLive();
        } elseif ($argv[1] === '--revoke') {
            $job->revoke();
        } else {
            Job::signalFailure();
        }
        exit(0);
    }
    if ($argc !== 4 || $argv[1] !== '--launch' || getenv('SILANSIA_BILLING_C3_PARENT_PID') !== false) {
        throw new LogicException('billing_c3_supervisor_command_invalid');
    }
    // PowerShell verifies these bootstrap bytes and the entire PHP tree before invoking this shim.
    require __DIR__.'/BillingC3Bootstrap.php';
    $job = Job::create($token);
    $arguments = [PHP_BINARY, ...BillingC3Bootstrap::phpOptions((string) ini_get('extension_dir')),
        __DIR__.'/run-payment-r2-integration.php', '--authorized-billing-c3', 'e2e', $argv[2], '--approved-manifest', $argv[3]];
    $shell = (getenv('WINDIR') ?: '').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    exit(Job::withLauncher((int) getenv('SILANSIA_BILLING_C3_SHELL_PID'), getenv('SILANSIA_BILLING_C3_SHELL_BIRTH') ?: '', $shell,
        static fn ($assertLive) => $job->supervise($arguments, getcwd(), Job::RUN_SECONDS, Job::BOOT_SECONDS, $assertLive)));
} catch (Throwable $error) {
    $code = $error instanceof LogicException && preg_match('/\Abilling_c3_[a-z0-9_]{1,70}\z/', $error->getMessage())
        ? $error->getMessage() : 'billing_c3_supervisor_failed';
    fwrite(STDERR, json_encode(['status' => 'BLOCKED', 'reason' => $code], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(78);
}
