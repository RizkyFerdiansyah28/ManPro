<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Throwable;

/** A process handle within the C3 job. A failure delegates tree shutdown to the native launcher. */
final class BillingC3Process
{
    private array $status;

    private ?array $identity = null;

    private function __construct(
        private mixed $handle,
        private mixed $output,
        private readonly int $deadline,
    ) {}

    public static function start(array $argv, string $root, array $env, int $seconds, int $capture = 65536, bool $afterRevocation = false): self
    {
        if (! $afterRevocation) {
            BillingC3ProcessJob::assertLive();
        } elseif (BillingC3ProcessJob::open()->hasFailed()) {
            throw new LogicException('billing_c3_admission_revoked');
        }
        $deadline = BillingC3ProcessJob::deadline($seconds);
        $file = null;
        try {
            if ($capture > 0) {
                $file = fopen(Runtime::path($root.'/c3-'.bin2hex(random_bytes(12)).'.stdout'), 'x+b');
                if (! is_resource($file)) {
                    throw new LogicException('billing_c3_process_output_failed');
                }
            }
            $handle = proc_open($argv, [0 => ['file', 'NUL', 'r'], 1 => $file ?? ['file', 'NUL', 'w'],
                2 => ['file', 'NUL', 'w']], $pipes, $root, $env, ['bypass_shell' => true, 'create_process_group' => true]);
            if (! is_resource($handle)) {
                throw new LogicException('billing_c3_process_launch_failed');
            }

            return new self($handle, $file, $deadline);
        } catch (Throwable $error) {
            BillingC3ProcessJob::signalFailure();
            if (is_resource($file)) {
                fclose($file);
            }
            throw $error;
        }
    }

    public static function run(array $argv, string $root, array $env, int $seconds, int $capture = 65536, bool $afterRevocation = false): array
    {
        return self::start($argv, $root, $env, $seconds, $capture, $afterRevocation)->await($capture);
    }

    public function pid(): int
    {
        if (! $this->poll()) {
            throw new LogicException('billing_c3_owned_process_exited');
        }
        $job = BillingC3ProcessJob::open();
        $this->identity ??= $job->process($this->status['pid']);
        $job->assertProcess($this->identity);

        return $this->identity['pid'];
    }

    public function poll(): bool
    {
        if (isset($this->status) && ! $this->status['running']) {
            return false;
        }
        $this->status = proc_get_status($this->handle);
        // A late exit after exceeding the stage deadline is also a failure.
        BillingC3ProcessJob::assertDeadline($this->deadline, 'billing_c3_process_deadline_exceeded');

        return $this->status['running'];
    }

    public function await(int $capture = 65536): array
    {
        try {
            while ($this->poll()) {
                usleep(25_000);
            }

            return $this->collect($capture);
        } catch (Throwable $error) {
            BillingC3ProcessJob::signalFailure();
            throw $error;
        }
    }

    /** PHP's CLI server has no shutdown endpoint. Only stop the exact live handle after revoking it. */
    public function stopServer(): void
    {
        $job = BillingC3ProcessJob::open();
        if (! $job->wasRevoked() || $job->hasFailed()) {
            throw new LogicException('billing_c3_web_stop_unadmitted');
        }
        $this->pid();
        if (! proc_terminate($this->handle)) {
            throw new LogicException('billing_c3_web_close_failed');
        }
        $deadline = BillingC3ProcessJob::deadline(BillingC3ProcessJob::EXIT_SECONDS);
        while ($this->poll()) {
            BillingC3ProcessJob::assertDeadline($deadline, 'billing_c3_web_close_unverified');
            usleep(25_000);
        }
        $this->collect(0);
    }

    private function collect(int $capture): array
    {
        // proc_close is only reached after proc_get_status observed an exit on this handle.
        $code = $this->status['exitcode'];
        proc_close($this->handle);
        $this->handle = null;
        if ($code < 0) {
            throw new LogicException('billing_c3_process_exit_unverified');
        }
        if ($this->output === null || $capture === 0) {
            return [$code, ''];
        }
        $output = fstat($this->output)['size'] <= $capture && rewind($this->output)
            ? stream_get_contents($this->output, $capture + 1) : false;
        if (! is_string($output) || strlen($output) > $capture) {
            throw new LogicException('billing_c3_process_output_limit');
        }

        return [$code, $output];
    }

    public function __destruct()
    {
        if (is_resource($this->output)) {
            fclose($this->output);
        }
    }
}
