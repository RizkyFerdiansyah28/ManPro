<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Support\PaymentR2IntegrationIdentity as Identity;

/** Invocation-local registry for one successful Windows pg_ctl start and its postmaster. */
final class BillingC3ClusterProcesses
{
    private function __construct(
        private readonly BillingC3ProcessHandle $launch,
        private readonly BillingC3ProcessHandle $wrapper,
        private readonly BillingC3ProcessHandle $postmaster,
        private readonly ?BillingC3ProcessHandle $consoleHost,
        private readonly array $cluster,
    ) {}

    public static function bind(BillingC3ProcessHandle $launch, array $cluster, string $cmdPath, string $postgresPath): self
    {
        BillingC3ProcessJob::assertLive();
        $job = BillingC3ProcessJob::open();
        $launch->assertJob($job);
        $exit = $launch->successfulExit();
        Identity::require($launch->parentPid() === getmypid(), 'billing_c3_cluster_launch_unverified');
        $postmaster = $job->retainProcess((int) $cluster['pid']);
        // Select the parent's actual process, never search by image name.
        $wrapper = $job->retainProcess($postmaster->parentPid());
        $wrapper->assertLive();
        $postmaster->assertLive();
        Identity::require((string) $postmaster->identity['pid'] === $cluster['pid'], 'billing_c3_cluster_process_binding_mismatch');
        self::assertLineage($launch->identity, $exit, $wrapper->identity, $wrapper->parentPid(), $postmaster->identity,
            $postmaster->parentPid(), $cmdPath, $postgresPath);

        $console = self::consoleHostPid($wrapper, $postmaster);
        $consoleHost = null;
        if (in_array($console, $job->members(), true)) {
            $consoleHost = $job->attestConsoleHost($console, [$launch->identity, $wrapper->identity, $postmaster->identity]);
            Identity::require($consoleHost->parentPid() !== $launch->identity['pid'] || strcmp($consoleHost->identity['birth'], $exit) <= 0,
                'billing_c3_console_host_unverified');
        }

        return new self($launch, $wrapper, $postmaster, $consoleHost, $cluster);
    }

    public static function assertLineage(array $launch, string $exit, array $wrapper, int $wrapperParent, array $postmaster,
        int $postmasterParent, string $cmdPath, string $postgresPath): void
    {
        Identity::require(strcasecmp($wrapper['image'], $cmdPath) === 0 && strcasecmp($postmaster['image'], $postgresPath) === 0
            && count(array_unique([$launch['pid'], $wrapper['pid'], $postmaster['pid']])) === 3
            && $wrapperParent === $launch['pid'] && $postmasterParent === $wrapper['pid']
            && strcmp($launch['birth'], $wrapper['birth']) <= 0 && strcmp($wrapper['birth'], $exit) <= 0
            && strcmp($wrapper['birth'], $postmaster['birth']) <= 0 && strcmp($postmaster['birth'], $exit) <= 0,
            'billing_c3_cluster_lineage_mismatch');
    }

    public function assertLive(array $cluster): void
    {
        $job = BillingC3ProcessJob::open();
        $this->launch->assertJob($job);
        Identity::require($this->cluster === $cluster && ! $job->hasFailed(), 'billing_c3_cluster_process_binding_mismatch');
        $this->wrapper->assertLive();
        $this->postmaster->assertLive();
        if ($this->consoleHost !== null) {
            $this->consoleHost->assertLive();
            Identity::require(self::consoleHostPid($this->wrapper, $this->postmaster) === $this->consoleHost->identity['pid'],
                'billing_c3_console_host_unverified');
        }
    }

    public function assertMembers(BillingC3ProcessJob $job, array $pids): void
    {
        $this->launch->assertJob($job);
        $this->assertLive($this->cluster);
        $required = [$this->wrapper->identity['pid'], $this->postmaster->identity['pid']];
        if ($this->consoleHost !== null) {
            $required[] = $this->consoleHost->identity['pid'];
        }
        Identity::require(array_diff($required, $pids) === [], 'billing_c3_cleanup_process_unverified');
        foreach (array_diff($pids, $required) as $pid) {
            $backend = $job->retainProcess($pid);
            Identity::require(strcasecmp($backend->identity['image'], $this->postmaster->identity['image']) === 0
                && $backend->parentPid() === $this->postmaster->identity['pid']
                && strcmp($backend->identity['birth'], $this->postmaster->identity['birth']) >= 0, 'billing_c3_cleanup_process_unverified');
        }
    }

    public function assertCleanShutdown(array $coordinators, int $deadline): void
    {
        do {
            BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_cluster_shutdown_processes_remaining');
            if ($this->shutdownProved($coordinators)) {
                BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_cluster_shutdown_processes_remaining');

                return;
            }
            usleep(25_000);
        } while (true);
    }

    private function shutdownProved(array $coordinators): bool
    {
        $job = BillingC3ProcessJob::open();
        $this->launch->assertJob($job);
        Identity::require($job->wasRevoked() && ! $job->hasFailed(), 'billing_c3_cleanup_process_unverified');
        if ($this->wrapper->running() || $this->postmaster->running() || $this->consoleHost?->running()) {
            return false;
        }
        [$members, $remaining] = $job->cleanupInventory($coordinators);
        $finished = [$this->launch->identity['pid'], $this->wrapper->identity['pid'], $this->postmaster->identity['pid']];
        if ($this->consoleHost !== null) {
            $finished[] = $this->consoleHost->identity['pid'];
        }
        if ($remaining !== [] && array_diff($remaining, $finished) === []) {
            // The waitable handles can signal before Windows removes those exact entries from the Job list.
            return false;
        }
        // Also reject any backend or unexpected process still in the Job.
        Identity::require($remaining === [] && $job->members() === $members, 'billing_c3_cleanup_process_unverified');

        return true;
    }

    private static function consoleHostPid(BillingC3ProcessHandle $wrapper, BillingC3ProcessHandle $postmaster): int
    {
        $console = $wrapper->consoleHostPid();
        Identity::require($console === $postmaster->consoleHostPid(), 'billing_c3_console_host_unverified');

        return $console;
    }
}
