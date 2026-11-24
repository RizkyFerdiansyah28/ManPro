<?php

declare(strict_types=1);

use Tests\Support\BillingC3ClusterProcesses as Cluster;
use Tests\Support\BillingC3ProcessHandle;
use Tests\Support\BillingC3ProcessJob as Job;

require __DIR__.'/PaymentR2IntegrationIdentity.php';
require __DIR__.'/BillingC3ProcessJob.php';
require __DIR__.'/BillingC3ProcessHandle.php';
require __DIR__.'/BillingC3ClusterProcesses.php';

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new LogicException($message);
    }
}

function until(callable $ready, string $message): void
{
    $deadline = Job::deadline(20);
    do {
        Job::assertDeadline($deadline, $message);
        clearstatcache();
        if ($ready()) {
            return;
        }
        usleep(20_000);
    } while (true);
}

function marker(string $path, string $value = 'ready'): void
{
    check(file_put_contents($path, $value, LOCK_EX) === strlen($value), 'fixture_marker_failed');
}

function markerPid(string $path): int
{
    $pid = null;
    until(static function () use ($path, &$pid): bool {
        clearstatcache(true, $path);
        $pid = is_file($path) ? file_get_contents($path) : null;

        return is_string($pid) && ctype_digit($pid) && (int) $pid > 0;
    }, 'fixture_pid_timeout');

    return (int) $pid;
}

function rejected(callable $action, string $reason): void
{
    try {
        $action();
    } catch (LogicException $error) {
        check($error->getMessage() === $reason, 'expected_'.$reason.'_got_'.$error->getMessage());

        return;
    }
    throw new LogicException('unexpected_success_'.$reason);
}

function launch(Job $job, string $root, string $label): BillingC3ProcessHandle
{
    $windows = getenv('SystemRoot');
    $handle = $job->launchClusterCommand([PHP_BINARY, '-n', __FILE__, $root, 'launcher', $label], $root, [
        'SystemRoot' => $windows, 'WINDIR' => $windows, 'ComSpec' => $windows.'\System32\cmd.exe',
        'PATH' => $windows.'\System32', 'TEMP' => $root, 'TMP' => $root,
    ]);
    $handle->awaitSuccess(Job::deadline(8));

    return $handle;
}

$verifiedRoot = false;
try {
    [$script, $root, $mode] = $argv;
    $label = $argv[3] ?? '';
    check(realpath($root) === $root && preg_match('/\Asilansia-c3-lineage-[a-f0-9]{24}\z/', basename($root)) === 1,
        'fixture_root_mismatch');
    $verifiedRoot = true;
    $cmd = getenv('SystemRoot').'\System32\cmd.exe';

    if (in_array($mode, ['launcher', 'worker', 'backend', 'drain'], true)) {
        check(in_array($label, ['primary', 'foreign', 'outside', 'timeout'], true), 'fixture_label_invalid');
    }
    if ($mode === 'worker' || $mode === 'backend' || $mode === 'drain') {
        $backend = null;
        if ($mode === 'worker' && $label === 'primary') {
            $backend = proc_open([PHP_BINARY, '-n', __FILE__, $root, 'backend', $label],
                [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
                $pipes, $root, null, ['bypass_shell' => true]);
            check(is_resource($backend), 'fixture_backend_spawn_failed');
            markerPid($root.'/backend-'.$label);
        }
        marker($root.'/'.$mode.'-'.$label, (string) getmypid());
        $stop = $mode === 'backend' ? 'worker' : $mode;
        until(static fn () => is_file($root.'/stop-'.$stop.'-'.$label), 'fixture_stop_timeout');
        if ($backend !== null) {
            until(static fn () => ! proc_get_status($backend)['running'], 'fixture_backend_exit_timeout');
            proc_close($backend);
        }
        exit(0);
    }
    if ($mode === 'launcher') {
        // This launcher exits once the synthetic postmaster is ready, while cmd.exe waits on it.
        $command = $cmd.' /D /C php.exe -n "'.__FILE__.'" "'.$root.'" worker '.$label;
        if ($label === 'primary') {
            $command .= ' & php.exe -n "'.__FILE__.'" "'.$root.'" drain '.$label;
        }
        $wrapper = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes, dirname(PHP_BINARY), null, ['bypass_shell' => true]);
        check(is_resource($wrapper), 'fixture_wrapper_spawn_failed');
        markerPid($root.'/worker-'.$label);
        check(proc_get_status($wrapper)['running'], 'fixture_wrapper_not_live');
        exit(0);
    }
    if ($mode === 'outside-supervisor') {
        $token = bin2hex(random_bytes(32));
        putenv('SILANSIA_BILLING_C3_JOB='.$token);
        $outside = Job::create($token);
        exit($outside->supervise([PHP_BINARY, '-n', '-d', 'extension='.dirname(PHP_BINARY).'/ext/php_ffi.dll', '-d', 'ffi.enable=1',
            __FILE__, $root, 'outside'], $root, 35));
    }

    check(in_array($mode, ['primary', 'outside', 'timeout'], true), 'fixture_mode_invalid');
    $job = Job::open();
    $self = $job->process(getmypid());
    $launch = launch($job, $root, $mode);
    $pid = markerPid($root.'/worker-'.$mode);
    $proof = ['invocation' => basename($root), 'pid' => (string) $pid, 'port' => '51000', 'system_id' => '1'];
    $cluster = Cluster::bind($launch, $proof, $cmd, PHP_BINARY);
    $wrapperPid = $job->retainProcess($pid)->parentPid();
    check(in_array($wrapperPid, $job->members(), true) && in_array($pid, $job->members(), true), 'fixture_owned_chain_not_live');

    if ($mode === 'timeout') {
        $foreignPid = markerPid($root.'/outside.wrapper');
        rejected(static fn () => $job->retainProcess($foreignPid), 'billing_c3_job_process_not_owned');
        marker($root.'/timeout.covered');
        // The outer deadline must revoke this Job and terminate its descendants only.
        sleep(25);
        exit(19);
    }
    if ($mode === 'outside') {
        marker($root.'/outside.wrapper', (string) $wrapperPid);
        until(static fn () => is_file($root.'/outside.ping'), 'fixture_outside_ping_timeout');
        $job->retainProcess($wrapperPid)->assertLive();
        $cluster->assertLive($proof);
        marker($root.'/outside.pong');
        until(static fn () => is_file($root.'/stop-worker-outside'), 'fixture_worker_stop_timeout');
        $job->revoke();
        $cluster->assertCleanShutdown([$self], Job::deadline(5));
        exit(0);
    }

    rejected(static fn () => $cluster->assertLive(array_replace($proof, ['system_id' => '2'])), 'billing_c3_cluster_process_binding_mismatch');
    rejected(static fn () => $job->attestConsoleHost($wrapperPid, [$launch->identity]), 'billing_c3_console_host_unverified');
    rejected(static fn () => Cluster::bind($launch, $proof, dirname($cmd).'\substitution\cmd.exe', PHP_BINARY),
        'billing_c3_cluster_lineage_mismatch');
    $foreign = launch($job, $root, 'foreign');
    $foreignPid = markerPid($root.'/worker-foreign');
    rejected(static fn () => Cluster::bind($launch, array_replace($proof, ['pid' => (string) $foreignPid]), $cmd, PHP_BINARY),
        'billing_c3_cluster_lineage_mismatch');
    $reused = $job->process($wrapperPid);
    $reused['birth'] = str_repeat('0', 16);
    rejected(static fn () => $job->assertProcess($reused), 'billing_c3_job_process_identity_mismatch');
    $job->revoke();
    rejected(static fn () => $job->assertCleanupProcesses([$self], $cluster), 'billing_c3_cleanup_process_unverified');
    $foreignWrapper = $job->retainProcess($job->retainProcess($foreignPid)->parentPid());
    marker($root.'/stop-worker-foreign');
    until(static fn () => ! $foreignWrapper->running()
        && array_intersect($job->members(), [$foreign->identity['pid'], $foreignWrapper->identity['pid'], $foreignPid]) === [], 'fixture_foreign_exit_timeout');
    $job->assertCleanupProcesses([$self], $cluster);
    marker($root.'/stop-worker-primary');
    markerPid($root.'/drain-primary');
    $postcheck = $job->retainProcess($wrapperPid);
    check($postcheck->running() && ! in_array($pid, $job->members(), true), 'fixture_expected_wrapper_only');
    rejected(static fn () => $cluster->assertCleanShutdown([$self], hrtime(true) + 150_000_000),
        'billing_c3_cluster_shutdown_processes_remaining');
    marker($root.'/stop-drain-primary');
    $cluster->assertCleanShutdown([$self], Job::deadline(5));
    check(! $postcheck->running(), 'fixture_cleanup_inventory_mismatch');
    $job->assertCleanupProcesses([$self]);
    exit(0);
} catch (Throwable $error) {
    $reason = get_class($error).': '.$error->getMessage();
    foreach ($error->getTrace() as $call) {
        if (isset($call['file'], $call['line']) && $call['file'] === __FILE__) {
            $reason .= ' (fixture line '.$call['line'].')';
            break;
        }
    }
    if (isset($job)) {
        try {
            $inventory = [];
            foreach ($job->members() as $member) {
                try {
                    $process = $job->retainProcess($member);
                    $inventory[] = $member.':'.basename($process->identity['image']).':'.$process->parentPid().':'.$process->consoleHostPid();
                } catch (LogicException) {
                    $inventory[] = $member.':unconfirmed';
                }
            }
            $reason .= ' (job inventory '.implode(', ', $inventory).')';
        } catch (LogicException) {
            // Preserve the original diagnostic if the Job disappeared.
        }
    }
    if ($verifiedRoot && preg_match('/\A[a-z-]+\z/', $mode) && preg_match('/\A[a-z]*\z/', $label)) {
        file_put_contents($root.'/error-'.$mode.'-'.$label, $reason);
    }
    fwrite(STDERR, $reason.PHP_EOL);
    exit(78);
}
