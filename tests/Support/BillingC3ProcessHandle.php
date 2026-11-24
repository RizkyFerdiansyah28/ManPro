<?php

declare(strict_types=1);

namespace Tests\Support;

use FFI;
use FFI\CData;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

/** Keeps the original Windows process object alive, even after its process exits. */
final class BillingC3ProcessHandle
{
    private static ?FFI $native = null;

    public function __construct(
        private readonly string $jobToken,
        private readonly FFI $kernel,
        private readonly CData $handle,
        public readonly array $identity,
    ) {}

    public function assertJob(BillingC3ProcessJob $job): void
    {
        Identity::require($job->matches($this->jobToken), 'billing_c3_cluster_job_mismatch');
    }

    private static function native(): FFI
    {
        // Windows x64 ProcessBasicInformation, read on the retained handle rather than a PID-only snapshot.
        return self::$native ??= FFI::cdef(<<<'C'
            typedef struct {
                int32_t exitStatus; void *peb; uint64_t affinity; int32_t priority;
                uint64_t pid; uint64_t parentPid;
            } PROCESS_BASIC_INFORMATION;
            int32_t NtQueryInformationProcess(void *process, int32_t kind, void *information, uint32_t length, uint32_t *returned);
            C, 'ntdll.dll');
    }

    public function parentPid(): int
    {
        $native = self::native();
        $facts = $native->new('PROCESS_BASIC_INFORMATION');
        $returned = $native->new('uint32_t');
        $size = FFI::sizeof($facts);
        Identity::require($native->NtQueryInformationProcess($this->handle, 0, FFI::addr($facts), $size, FFI::addr($returned)) === 0
            && $returned->cdata === $size && $facts->pid === $this->identity['pid'] && $facts->parentPid > 0,
            'billing_c3_cluster_parent_unverified');

        return $facts->parentPid;
    }

    public function consoleHostPid(): int
    {
        $native = self::native();
        $value = $native->new('uint64_t');
        $returned = $native->new('uint32_t');
        $size = FFI::sizeof($value);
        // Windows x64 ProcessConsoleHostProcess. Low bits carry kernel flags, not part of the PID.
        Identity::require($native->NtQueryInformationProcess($this->handle, 49, FFI::addr($value), $size, FFI::addr($returned)) === 0
            && $returned->cdata === $size, 'billing_c3_console_host_unverified');
        $pid = $value->cdata & ~3;
        Identity::require($pid >= 0 && $pid <= 0xFFFFFFFF, 'billing_c3_console_host_unverified');

        return $pid;
    }

    public function running(): bool
    {
        $status = $this->kernel->WaitForSingleObject($this->handle, 0);
        Identity::require(in_array($status, [0, 258], true), 'billing_c3_job_wait_failed');

        return $status === 258;
    }

    public function assertLive(): void
    {
        Identity::require($this->running(), 'billing_c3_cluster_process_exited');
        $job = BillingC3ProcessJob::open();
        $this->assertJob($job);
        $job->assertProcess($this->identity);
        Identity::require($this->running(), 'billing_c3_cluster_process_exited');
    }

    public function successfulExit(): string
    {
        Identity::require(! $this->running(), 'billing_c3_cluster_launch_unverified');
        $code = $this->kernel->new('DWORD');
        Identity::require($this->kernel->GetExitCodeProcess($this->handle, FFI::addr($code)) !== 0 && $code->cdata === 0,
            'billing_c3_cluster_launch_failed');
        $times = $this->kernel->new('FILETIME[4]');
        Identity::require($this->kernel->GetProcessTimes($this->handle, FFI::addr($times[0]), FFI::addr($times[1]),
            FFI::addr($times[2]), FFI::addr($times[3])) !== 0, 'billing_c3_cluster_launch_unverified');
        $birth = sprintf('%08x%08x', $times[0]->high, $times[0]->low);
        $exit = sprintf('%08x%08x', $times[1]->high, $times[1]->low);
        Identity::require($birth === $this->identity['birth'] && strcmp($exit, $birth) >= 0, 'billing_c3_cluster_launch_unverified');

        return $exit;
    }

    public function awaitSuccess(int $deadline): void
    {
        do {
            try {
                BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_cluster_launch_deadline');
                $this->assertAdmission();
                if (! $this->running()) {
                    $this->successfulExit();

                    return;
                }
            } catch (\Throwable $error) {
                BillingC3ProcessJob::signalFailure();
                throw $error;
            }
            usleep(25_000);
        } while (true);
    }

    private function assertAdmission(): void
    {
        $job = BillingC3ProcessJob::open();
        $this->assertJob($job);
        Identity::require(! $job->wasRevoked() && ! $job->hasFailed(), 'billing_c3_admission_revoked');
    }

    public function __destruct()
    {
        $this->kernel->CloseHandle($this->handle);
    }
}
