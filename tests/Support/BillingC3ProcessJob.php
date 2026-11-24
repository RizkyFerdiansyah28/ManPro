<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use FFI;
use FFI\CData;
use LogicException;
use Throwable;

/** Native Windows handles. The launcher owns the only job handle that can terminate the tree. */
final class BillingC3ProcessJob
{
    public const BOOT_SECONDS = 75;

    public const HASH_SECONDS = 60;

    public const CHILD_SECONDS = 1800;

    public const RUN_SECONDS = self::BOOT_SECONDS + self::CHILD_SECONDS + 120;

    public const ASSET_SECONDS = 160;

    public const BROWSER_SECONDS = 1100;

    public const WEB_READY_SECONDS = 25;

    public const EXIT_SECONDS = 12;

    public const DRAIN_SECONDS = 3;

    private static ?FFI $api = null;

    private function __construct(
        private readonly CData $job,
        private readonly CData $revoked,
        private readonly CData $failed,
        private readonly CData $ready,
        private readonly string $token,
    ) {}

    private static function api(): FFI
    {
        self::require(PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8 && extension_loaded('ffi'), 'billing_c3_job_windows_required');

        return self::$api ??= FFI::cdef(<<<'C'
            typedef void *HANDLE;
            typedef unsigned int DWORD;
            typedef unsigned short WCHAR;
            typedef unsigned long long SIZE_T;
            typedef struct { DWORD length; void *descriptor; int inherit; } SECURITY_ATTRIBUTES;
            typedef struct { DWORD low; DWORD high; } FILETIME;
            typedef struct {
                long long perProcess; long long perJob; DWORD flags;
                SIZE_T minWorking; SIZE_T maxWorking; DWORD active;
                SIZE_T affinity; DWORD priority; DWORD scheduling;
            } BASIC_LIMIT;
            typedef struct {
                BASIC_LIMIT basic; unsigned long long io[6];
                SIZE_T processMemory; SIZE_T jobMemory; SIZE_T peakProcess; SIZE_T peakJob;
            } EXTENDED_LIMIT;
            typedef struct { DWORD assigned; DWORD count; SIZE_T pids[1024]; } PROCESS_LIST;
            typedef struct {
                DWORD cb; WCHAR *reserved; WCHAR *desktop; WCHAR *title;
                DWORD x; DWORD y; DWORD xSize; DWORD ySize; DWORD xChars; DWORD yChars;
                DWORD fill; DWORD flags; unsigned short show; unsigned short reserved2;
                void *data; HANDLE input; HANDLE output; HANDLE error;
            } STARTUP;
            typedef struct { HANDLE process; HANDLE thread; DWORD pid; DWORD tid; } PROCESS_INFO;
            HANDLE CreateJobObjectW(void *attributes, const WCHAR *name);
            HANDLE OpenJobObjectW(DWORD access, int inherit, const WCHAR *name);
            int SetInformationJobObject(HANDLE job, int type, void *value, DWORD bytes);
            int QueryInformationJobObject(HANDLE job, int type, void *value, DWORD bytes, DWORD *returned);
            int AssignProcessToJobObject(HANDLE job, HANDLE process);
            int IsProcessInJob(HANDLE process, HANDLE job, int *result);
            int TerminateJobObject(HANDLE job, unsigned int code);
            HANDLE CreateEventW(void *attributes, int manual, int initial, const WCHAR *name);
            HANDLE OpenEventW(DWORD access, int inherit, const WCHAR *name);
            int SetEvent(HANDLE event);
            DWORD GetLastError(void);
            void SetLastError(DWORD error);
            HANDLE GetCurrentProcess(void);
            HANDLE OpenProcess(DWORD access, int inherit, DWORD pid);
            int GetProcessTimes(HANDLE process, FILETIME *created, FILETIME *exit, FILETIME *kernel, FILETIME *user);
            int QueryFullProcessImageNameW(HANDLE process, DWORD flags, WCHAR *path, DWORD *length);
            DWORD GetSystemDirectoryW(WCHAR *path, DWORD length);
            int CreateProcessW(const WCHAR *exe, WCHAR *command, void *processAttributes, void *threadAttributes,
                int inherit, DWORD flags, void *environment, const WCHAR *dir, STARTUP *startup, PROCESS_INFO *info);
            HANDLE GetStdHandle(DWORD which);
            HANDLE CreateFileW(const WCHAR *path, DWORD access, DWORD share, SECURITY_ATTRIBUTES *attributes,
                DWORD disposition, DWORD flags, HANDLE templateFile);
            DWORD GetFileType(HANDLE handle);
            int DuplicateHandle(HANDLE source, HANDLE value, HANDLE target, HANDLE *duplicate, DWORD access, int inherit, DWORD options);
            DWORD ResumeThread(HANDLE thread);
            int TerminateProcess(HANDLE process, unsigned int code);
            DWORD WaitForSingleObject(HANDLE object, DWORD milliseconds);
            int GetExitCodeProcess(HANDLE process, DWORD *code);
            int CloseHandle(HANDLE handle);
            C, 'kernel32.dll');
    }

    public static function create(string $token): self
    {
        $api = self::api();
        $api->SetLastError(0);
        $job = $api->CreateJobObjectW(null, self::name($token, 'Job'));
        $fresh = $api->GetLastError() !== 183;
        self::require(self::valid($job), 'billing_c3_job_create_failed');
        try {
            self::require($fresh, 'billing_c3_job_name_collision');
            $limit = $api->new('EXTENDED_LIMIT');
            // KILL_ON_JOB_CLOSE only. Neither BREAKAWAY_OK flag is permitted.
            $limit->basic->flags = 0x2000;
            self::require($api->SetInformationJobObject($job, 9, FFI::addr($limit), FFI::sizeof($limit)) !== 0, 'billing_c3_job_limits_failed');
            $revoked = self::createEvent($token, 'Revoked');
            try {
                $failed = self::createEvent($token, 'Failed');
                try {
                    return new self($job, $revoked, $failed, self::createEvent($token, 'Ready'), $token);
                } catch (Throwable $error) {
                    $api->CloseHandle($failed);
                    throw $error;
                }
            } catch (Throwable $error) {
                $api->CloseHandle($revoked);
                throw $error;
            }
        } catch (Throwable $error) {
            $api->CloseHandle($job);
            throw $error;
        }
    }

    private static function createEvent(string $token, string $type): CData
    {
        $api = self::api();
        $api->SetLastError(0);
        $event = $api->CreateEventW(null, 1, 0, self::name($token, $type));
        $fresh = $api->GetLastError() !== 183;
        self::require(self::valid($event), 'billing_c3_job_event_create_failed');
        if (! $fresh) {
            $api->CloseHandle($event);
            throw new LogicException('billing_c3_job_name_collision');
        }

        return $event;
    }

    public static function open(): self
    {
        $token = getenv('SILANSIA_BILLING_C3_JOB') ?: '';
        $api = self::api();
        // Read-only job handle. A child may revoke admission or signal failure.
        $job = $api->OpenJobObjectW(4, 0, self::name($token, 'Job'));
        self::require(self::valid($job), 'billing_c3_job_missing');
        $revoked = $failed = $ready = null;
        try {
            $revoked = $api->OpenEventW(0x100002, 0, self::name($token, 'Revoked'));
            $failed = $api->OpenEventW(0x100002, 0, self::name($token, 'Failed'));
            $ready = $api->OpenEventW(0x100002, 0, self::name($token, 'Ready'));
            self::require(self::valid($revoked) && self::valid($failed) && self::valid($ready), 'billing_c3_job_event_missing');
            $context = new self($job, $revoked, $failed, $ready, $token);
        } catch (Throwable $error) {
            foreach ([$job, $revoked, $failed, $ready] as $handle) {
                if (self::valid($handle)) {
                    $api->CloseHandle($handle);
                }
            }
            throw $error;
        }
        $context->assertHandle(self::api()->GetCurrentProcess());

        return $context;
    }

    private static function name(string $token, string $type): CData
    {
        self::require(preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'billing_c3_job_token_invalid');

        return self::wide('Local\\SilansiaC3-'.$token.'-'.$type);
    }

    public static function settings(): array
    {
        return ['SILANSIA_BILLING_C3_JOB' => getenv('SILANSIA_BILLING_C3_JOB'),
            'SILANSIA_BILLING_C3_PARENT_PID' => getenv('SILANSIA_BILLING_C3_PARENT_PID'),
            'SILANSIA_BILLING_C3_PARENT_BIRTH' => getenv('SILANSIA_BILLING_C3_PARENT_BIRTH'),
            'SILANSIA_BILLING_C3_CHILD_PID' => getenv('SILANSIA_BILLING_C3_CHILD_PID'),
            'SILANSIA_BILLING_C3_CHILD_BIRTH' => getenv('SILANSIA_BILLING_C3_CHILD_BIRTH')];
    }

    public static function assertLive(): void
    {
        $job = self::open();
        self::require(! $job->wasRevoked() && ! $job->hasFailed(), 'billing_c3_admission_revoked');
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->token, $token);
    }

    public function wasRevoked(): bool
    {
        return self::event($this->revoked);
    }

    public function hasFailed(): bool
    {
        return self::event($this->failed);
    }

    private static function event(CData $event): bool
    {
        $result = self::api()->WaitForSingleObject($event, 0);
        self::require(in_array($result, [0, 258], true), 'billing_c3_job_event_unverified');

        return $result === 0;
    }

    public function revoke(): void
    {
        self::require(self::api()->SetEvent($this->revoked) !== 0 && $this->wasRevoked(), 'billing_c3_job_revocation_unverified');
    }

    public static function markReady(): void
    {
        $job = self::open();
        self::require(! $job->hasFailed() && ! $job->wasRevoked() && self::api()->SetEvent($job->ready) !== 0,
            'billing_c3_job_readiness_unverified');
    }

    public static function signalFailure(): void
    {
        try {
            $job = self::open();
            $job->revoke();
            self::require(self::api()->SetEvent($job->failed) !== 0, 'billing_c3_job_failure_unverified');
        } catch (Throwable) {
            // The outer launcher still enforces its independent deadline.
        }
    }

    /** Create the root PHP process suspended, put it in the job, then let the first instruction execute. */
    private function start(array $argv, string $dir, ?array $environment = null): array
    {
        $api = self::api();
        $startup = $api->new('STARTUP');
        $startup->cb = FFI::sizeof($startup);
        $startup->flags = 0x100;
        $info = $api->new('PROCESS_INFO');
        $handles = [];
        try {
            if ($environment === null) {
                $current = $api->GetCurrentProcess();
                foreach (['input' => 0xFFFFFFF6, 'output' => 0xFFFFFFF5, 'error' => 0xFFFFFFF4] as $field => $number) {
                    $source = $api->GetStdHandle($number);
                    $duplicate = $api->new('HANDLE');
                    self::require(self::valid($source) && $api->DuplicateHandle($current, $source, $current, FFI::addr($duplicate), 0, 1, 2) !== 0,
                        'billing_c3_job_stdio_unverified');
                    $handles[] = $duplicate;
                    $startup->$field = $duplicate;
                }
            } else {
                // pg_ctl supplies the postmaster log itself. Keep startup chatter out of the coordinator's protocol.
                $attributes = $api->new('SECURITY_ATTRIBUTES');
                $attributes->length = FFI::sizeof($attributes);
                $attributes->inherit = 1;
                $null = $api->CreateFileW(self::wide('NUL'), 0xC0000000, 3, FFI::addr($attributes), 3, 0, null);
                self::require(self::valid($null) && $api->GetFileType($null) === 2, 'billing_c3_job_stdio_unverified');
                $handles[] = $null;
                $startup->input = $startup->output = $startup->error = $null;
            }
            $line = implode(' ', array_map(self::quote(...), $argv));
            // CREATE_SUSPENDED | CREATE_NO_WINDOW, no breakaway. No shell involved.
            $block = $environment === null ? null : self::environmentBlock($environment);
            $flags = $block === null ? 0x08000004 : 0x08000404; // CREATE_UNICODE_ENVIRONMENT for the explicit block.
            self::require($api->CreateProcessW(self::wide($argv[0]), self::wide($line), null, null, 1, $flags,
                $block, self::wide($dir), FFI::addr($startup), FFI::addr($info)) !== 0, 'billing_c3_job_spawn_failed');
        } finally {
            foreach ($handles as $handle) {
                $api->CloseHandle($handle);
            }
        }
        try {
            if ($environment === null) {
                self::require($api->AssignProcessToJobObject($this->job, $info->process) !== 0, 'billing_c3_job_assignment_failed');
            }
            $this->assertHandle($info->process);
            $facts = $environment === null ? null : self::processFacts($info->process, $info->pid);
            if ($facts !== null) {
                self::require(strcasecmp(str_replace('/', '\\', $argv[0]), $facts['image']) === 0
                    && ! $this->wasRevoked() && ! $this->hasFailed(), 'billing_c3_cluster_launch_unverified');
            }
            self::require($api->ResumeThread($info->thread) === 1, 'billing_c3_job_resume_failed');
        } catch (Throwable $error) {
            $this->revoke();
            // This is the exact handle just returned by CreateProcessW, even if assignment failed.
            self::require($api->TerminateProcess($info->process, 78) !== 0
                && $api->WaitForSingleObject($info->process, self::EXIT_SECONDS * 1000) === 0, 'billing_c3_job_exit_unverified');
            $api->CloseHandle($info->process);
            throw $error;
        } finally {
            $api->CloseHandle($info->thread);
        }

        return [$info->process, $facts];
    }

    private static function environmentBlock(array $environment): CData
    {
        $entries = [];
        foreach ($environment as $key => $value) {
            self::require(preg_match('/\A[A-Za-z_][A-Za-z0-9_()]*\z/', $key) === 1 && is_string($value)
                && ! str_contains($value, "\0") && ! isset($entries[strtoupper($key)]), 'billing_c3_job_environment_invalid');
            $entries[strtoupper($key)] = $key.'='.$value;
        }
        ksort($entries, SORT_STRING);

        return self::wide(implode("\0", $entries)."\0");
    }

    public function launchClusterCommand(array $argv, string $dir, array $environment): BillingC3ProcessHandle
    {
        $this->assertHandle(self::api()->GetCurrentProcess());
        self::require(! $this->wasRevoked() && ! $this->hasFailed(), 'billing_c3_admission_revoked');
        [$handle, $identity] = $this->start($argv, $dir, $environment);

        return new BillingC3ProcessHandle($this->token, self::api(), $handle, $identity);
    }

    public function supervise(array $argv, string $dir, int $seconds, ?int $bootSeconds = null, ?Closure $assertLauncher = null): int
    {
        $api = self::api();
        $assertLauncher?->__invoke();
        [$process] = $this->start($argv, $dir);
        try {
            $deadline = self::deadline($seconds);
            $bootstrap = $bootSeconds === null ? $deadline : self::deadline($bootSeconds);
            do {
                $state = $api->WaitForSingleObject($process, 25);
                $assertLauncher?->__invoke();
                self::require(in_array($state, [0, 258], true), 'billing_c3_job_wait_failed');
                self::assertDeadline($deadline, 'billing_c3_job_deadline_exceeded');
                if ($bootSeconds !== null && ! self::event($this->ready)) {
                    self::assertDeadline($bootstrap, 'billing_c3_bootstrap_deadline_exceeded');
                }
                if ($state === 0) {
                    $code = $api->new('DWORD');
                    self::require($api->GetExitCodeProcess($process, FFI::addr($code)) !== 0, 'billing_c3_job_exit_unverified');
                    self::require($code->cdata === 0 && ! $this->hasFailed(), 'billing_c3_supervised_parent_failed');
                    self::require($bootSeconds === null || self::event($this->ready), 'billing_c3_job_readiness_unverified');
                    self::awaitEmpty($this->members(...), min($deadline, self::deadline(1)), 'billing_c3_job_descendant_remaining');

                    return 0;
                }
                self::require(! $this->hasFailed(), 'billing_c3_process_failure_signalled');
            } while (hrtime(true) < $deadline);

            throw new LogicException('billing_c3_job_deadline_exceeded');
        } catch (Throwable $error) {
            $this->stopTree();
            throw $error;
        } finally {
            $api->CloseHandle($process);
        }
    }

    private function stopTree(): void
    {
        $this->revoke();
        $drain = self::deadline(self::DRAIN_SECONDS);
        try {
            do {
                if ($this->members() === []) {
                    return;
                }
                usleep(25_000);
            } while (hrtime(true) < $drain);
        } catch (LogicException) {
            // A failed query is not proof of emptiness. The existing job handle still scopes termination.
        }
        self::require(self::api()->TerminateJobObject($this->job, 78) !== 0, 'billing_c3_job_termination_unverified');
        self::awaitEmpty($this->members(...), self::deadline(self::EXIT_SECONDS));
    }

    public static function awaitEmpty(callable $members, int $deadline, string $reason = 'billing_c3_job_exit_unverified'): void
    {
        do {
            if ($members() === []) {
                self::assertDeadline($deadline, $reason);

                return;
            }
            usleep(25_000);
        } while (hrtime(true) < $deadline);

        throw new LogicException($reason);
    }

    public function members(): array
    {
        $api = self::api();
        $result = $api->new('PROCESS_LIST');
        self::require($api->QueryInformationJobObject($this->job, 3, FFI::addr($result), FFI::sizeof($result), null) !== 0
            && $result->assigned === $result->count && $result->count <= 1024, 'billing_c3_job_inventory_unverified');
        $pids = [];
        for ($i = 0; $i < $result->count; $i++) {
            $pids[] = $result->pids[$i];
        }
        sort($pids, SORT_NUMERIC);

        return $pids;
    }

    public function process(int $pid): array
    {
        $api = self::api();
        $handle = $api->OpenProcess(0x101000, 0, $pid); // SYNCHRONIZE | QUERY_LIMITED_INFORMATION
        self::require(self::valid($handle), 'billing_c3_job_process_unverified');
        try {
            $this->assertHandle($handle);

            return self::processFacts($handle, $pid);
        } finally {
            $api->CloseHandle($handle);
        }
    }

    public function retainProcess(int $pid): BillingC3ProcessHandle
    {
        $api = self::api();
        $handle = $api->OpenProcess(0x101000, 0, $pid); // Non-inheritable SYNCHRONIZE | QUERY_LIMITED_INFORMATION.
        self::require(self::valid($handle), 'billing_c3_job_process_unverified');
        try {
            $this->assertHandle($handle);

            return new BillingC3ProcessHandle($this->token, $api, $handle, self::processFacts($handle, $pid));
        } catch (Throwable $error) {
            $api->CloseHandle($handle);
            throw $error;
        }
    }

    /** Keep a non-inheritable handle to the reviewed PowerShell instance for the whole invocation. */
    public static function withLauncher(int $pid, string $birth, string $image, callable $run): int
    {
        $api = self::api();
        $handle = $api->OpenProcess(0x101000, 0, $pid);
        self::require(self::valid($handle), 'billing_c3_launcher_unverified');
        try {
            $facts = self::processFacts($handle, $pid);
            self::require(hash_equals($facts['birth'], $birth) && strcasecmp($facts['image'], $image) === 0, 'billing_c3_launcher_unverified');
            $assertLive = static fn () => self::require($api->WaitForSingleObject($handle, 0) === 258, 'billing_c3_launcher_exited');

            return $run($assertLive);
        } finally {
            $api->CloseHandle($handle);
        }
    }

    private static function processFacts(CData $handle, int $pid): array
    {
        $api = self::api();
        self::require($api->WaitForSingleObject($handle, 0) === 258, 'billing_c3_job_process_exited');
        $times = $api->new('FILETIME[4]');
        $length = $api->new('DWORD');
        $length->cdata = 1024;
        $path = $api->new('WCHAR[1024]');
        self::require($api->GetProcessTimes($handle, FFI::addr($times[0]), FFI::addr($times[1]), FFI::addr($times[2]), FFI::addr($times[3])) !== 0
            && $api->QueryFullProcessImageNameW($handle, 0, $path, FFI::addr($length)) !== 0
            && $length->cdata > 0 && $length->cdata < 1024, 'billing_c3_job_process_unverified');
        $image = iconv('UTF-16LE', 'UTF-8', FFI::string($api->cast('char *', FFI::addr($path[0])), $length->cdata * 2));
        self::require(is_string($image), 'billing_c3_job_process_unverified');

        return ['pid' => $pid, 'birth' => sprintf('%08x%08x', $times[0]->high, $times[0]->low), 'image' => $image];
    }

    public function assertProcess(array $expected): void
    {
        self::require($this->process($expected['pid']) === $expected, 'billing_c3_job_process_identity_mismatch');
    }

    public function assertCleanupProcesses(array $coordinators, ?BillingC3ClusterProcesses $cluster = null): void
    {
        [$members, $remaining] = $this->cleanupInventory($coordinators);
        if ($cluster === null) {
            self::require($remaining === [], 'billing_c3_cleanup_process_unverified');
        } else {
            $cluster->assertMembers($this, $remaining);
        }
        self::require($this->members() === $members, 'billing_c3_cleanup_process_unverified');
    }

    public function cleanupInventory(array $coordinators): array
    {
        self::require($this->wasRevoked() && ! $this->hasFailed(), 'billing_c3_cleanup_process_unverified');
        $members = $this->members();
        $expected = [];
        $consoleHosts = [];
        foreach ($coordinators as $coordinator) {
            $handle = $this->retainProcess($coordinator['pid']);
            self::require($handle->identity === $coordinator, 'billing_c3_job_process_identity_mismatch');
            $expected[] = $coordinator['pid'];
            $console = $handle->consoleHostPid();
            if (in_array($console, $members, true)) {
                $consoleHosts[$console] ??= $this->attestConsoleHost($console, $coordinators);
            }
        }
        self::require(array_diff($expected, $members) === [], 'billing_c3_cleanup_process_unverified');

        return [$members, array_diff($members, $expected, array_keys($consoleHosts))];
    }

    public function attestConsoleHost(int $pid, array $owners): BillingC3ProcessHandle
    {
        $host = $this->retainProcess($pid);
        $api = self::api();
        $buffer = $api->new('WCHAR[512]');
        $length = $api->GetSystemDirectoryW($buffer, 512);
        self::require($length > 0 && $length < 512, 'billing_c3_console_host_unverified');
        $system = iconv('UTF-16LE', 'UTF-8', FFI::string($api->cast('char *', FFI::addr($buffer[0])), $length * 2));
        self::require(is_string($system) && strcasecmp($host->identity['image'], $system.'\\conhost.exe') === 0,
            'billing_c3_console_host_unverified');
        $parent = $host->parentPid();
        foreach ($owners as $owner) {
            if ($parent === $owner['pid'] && strcmp($host->identity['birth'], $owner['birth']) >= 0) {
                return $host;
            }
        }

        throw new LogicException('billing_c3_console_host_unverified');
    }

    private function assertHandle(CData $handle): void
    {
        $api = self::api();
        $member = $api->new('int');
        self::require($api->IsProcessInJob($handle, $this->job, FFI::addr($member)) !== 0 && $member->cdata !== 0,
            'billing_c3_job_process_not_owned');
    }

    public function __destruct()
    {
        foreach ([$this->ready, $this->revoked, $this->failed, $this->job] as $handle) {
            self::api()->CloseHandle($handle);
        }
    }

    public static function deadline(int $seconds): int
    {
        return hrtime(true) + $seconds * 1_000_000_000;
    }

    public static function assertDeadline(int $deadline, string $reason): void
    {
        self::require(hrtime(true) < $deadline, $reason);
    }

    private static function quote(string $value): string
    {
        self::require(! preg_match('/["\x00-\x1f]/', $value), 'billing_c3_job_invalid_command');
        $trimmed = rtrim($value, '\\');

        return '"'.$trimmed.str_repeat('\\', 2 * (strlen($value) - strlen($trimmed))).'"';
    }

    private static function wide(string $value): CData
    {
        $bytes = iconv('UTF-8', 'UTF-16LE', $value."\0");
        self::require(is_string($bytes), 'billing_c3_job_invalid_command');
        $buffer = self::api()->new('WCHAR['.intdiv(strlen($bytes), 2).']');
        FFI::memcpy($buffer, $bytes, strlen($bytes));

        return $buffer;
    }

    private static function valid(?CData $handle): bool
    {
        return $handle !== null && ! FFI::isNull($handle);
    }

    private static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new LogicException($reason);
        }
    }
}
