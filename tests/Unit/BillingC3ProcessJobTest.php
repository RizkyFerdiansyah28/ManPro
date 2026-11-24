<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC3ProcessJob as Job;

final class BillingC3ProcessJobTest extends TestCase
{
    public function test_owned_child_can_attest_and_exit_before_deadline(): void
    {
        $this->withJob(static function (Job $job): void {
            self::assertSame(0, $job->supervise(self::php('JOB::assertLive(); JOB::markReady();'), __DIR__, 10, 5));
            self::assertSame([], $job->members());
            self::assertFalse($job->wasRevoked());
        });
    }

    public function test_web_style_stop_requires_revocation_and_waits_for_exact_handle(): void
    {
        $this->withJob(static function (Job $job): void {
            $code = <<<'PHP'
                $server = \Tests\Support\BillingC3Process::start([PHP_BINARY, '-n', '-r', 'sleep(30);'], getcwd(), [], 10, 0);
                if ($server->pid() < 1) exit(23);
                try {
                    $server->stopServer();
                    exit(22);
                } catch (LogicException $e) {
                    if ($e->getMessage() !== 'billing_c3_web_stop_unadmitted') exit(24);
                }
                JOB::open()->revoke();
                $job = JOB::open();
                $self = $job->process(getmypid());
                try {
                    $job->assertCleanupProcesses([$self]);
                    exit(27);
                } catch (LogicException $e) {
                    if ($e->getMessage() !== 'billing_c3_cleanup_process_unverified') exit(28);
                }
                $server->stopServer();
                try {
                    JOB::assertLive();
                    exit(25);
                } catch (LogicException $e) {
                    exit($e->getMessage() === 'billing_c3_admission_revoked' ? 0 : 26);
                }
                PHP;
            self::assertSame(0, $job->supervise(self::php($code), __DIR__, 12));
            self::assertTrue($job->wasRevoked());
            self::assertSame([], $job->members());
        });
    }

    #[DataProvider('failures')]
    public function test_native_supervisor_fails_closed(string $code, ?string $expected, int $seconds = 10, ?int $bootstrap = null): void
    {
        $this->withJob(static function (Job $job) use ($code, $expected, $seconds, $bootstrap): void {
            if ($expected === null) {
                self::assertSame(0, $job->supervise(self::php($code), __DIR__, $seconds, $bootstrap));
                self::assertSame([], $job->members());

                return;
            }
            try {
                $job->supervise(self::php($code), __DIR__, $seconds, $bootstrap);
                self::fail('Unsafe process state accepted as success.');
            } catch (LogicException $error) {
                self::assertSame($expected, $error->getMessage());
            }
            self::assertTrue($job->wasRevoked());
            self::assertSame([], $job->members());
        });
    }

    public static function failures(): array
    {
        return [
            'child timeout' => ['sleep(30);', 'billing_c3_job_deadline_exceeded', 1],
            'bootstrap exception' => ['exit(21);', 'billing_c3_supervised_parent_failed'],
            'bootstrap dependency does not finish' => ['sleep(30); JOB::markReady();', 'billing_c3_bootstrap_deadline_exceeded', 10, 1],
            'unreleased barrier aborts' => [
                <<<'PHP'
                    try {
                        \Tests\Support\BillingC3Race::await(static fn () => false, 0.1);
                        exit(21);
                    } catch (LogicException $e) {
                        if ($e->getMessage() !== 'billing_c3_race_barrier_timeout') exit(22);
                        if (!JOB::open()->wasRevoked()) JOB::open()->revoke();
                        exit(0);
                    }
                    PHP,
                null,
            ],
            'coordinator exited but grandchild remains' => [
                <<<'PHP'
                    $child = proc_open([PHP_BINARY, '-n', '-r', 'sleep(30);'], [
                        0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w'],
                    ], $pipes, __DIR__, [], ['bypass_shell' => true, 'create_process_group' => true]);
                    exit(is_resource($child) ? 0 : 22);
                    PHP,
                'billing_c3_job_descendant_remaining',
            ],
            'outside process identity denied' => [
                <<<'PHP'
                    try {
                        JOB::open()->process((int) getenv('SYNTHETIC_TEST_PARENT'));
                        exit(21);
                    } catch (LogicException $e) {
                        exit($e->getMessage() === 'billing_c3_job_process_not_owned' ? 0 : 22);
                    }
                    PHP,
                null,
            ],
            'same pid with a different birth stamp is denied' => [
                <<<'PHP'
                    $job = JOB::open();
                    try {
                        $self = $job->process(getmypid());
                        $self['birth'] = str_repeat('0', 16);
                        $job->assertProcess($self);
                        exit(21);
                    } catch (LogicException $e) {
                        if ($e->getMessage() !== 'billing_c3_job_process_identity_mismatch') fwrite(STDERR, $e->getMessage().PHP_EOL);
                        exit($e->getMessage() === 'billing_c3_job_process_identity_mismatch' ? 0 : 22);
                    }
                    PHP,
                null,
            ],
        ];
    }

    public function test_termination_without_empty_membership_blocks_cleanup(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-c3-synthetic-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($root));
        $file = $root.DIRECTORY_SEPARATOR.'preserve.txt';
        file_put_contents($file, 'synthetic marker');
        try {
            try {
                Job::awaitEmpty(static fn () => [1], hrtime(true) + 30_000_000);
                unlink($file);
                self::fail('An occupied job was accepted for cleanup.');
            } catch (LogicException $error) {
                self::assertSame('billing_c3_job_exit_unverified', $error->getMessage());
            }
            self::assertSame('synthetic marker', file_get_contents($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    public function test_native_termination_denied_is_not_an_exit_proof(): void
    {
        $this->withJob(static function (Job $job): void {
            $code = <<<'PHP'
                $job = JOB::open();
                try {
                    $stop = new ReflectionMethod($job, 'stopTree');
                    $stop->invoke($job);
                    exit(22);
                } catch (LogicException $e) {
                    exit($job->wasRevoked() && $e->getMessage() === 'billing_c3_job_termination_unverified' ? 0 : 23);
                }
                PHP;
            self::assertSame(0, $job->supervise(self::php($code), __DIR__, 12));
            self::assertTrue($job->wasRevoked());
            self::assertSame([], $job->members());
        });
    }

    public function test_lost_launcher_revokes_and_terminates_its_child(): void
    {
        $this->withJob(static function (Job $job): void {
            $polls = 0;
            try {
                $job->supervise(self::php('sleep(30);'), __DIR__, 12, assertLauncher: static function () use (&$polls): void {
                    if (++$polls === 2) {
                        throw new LogicException('billing_c3_launcher_exited');
                    }
                });
                self::fail('Supervisor accepted an absent launcher.');
            } catch (LogicException $error) {
                self::assertSame('billing_c3_launcher_exited', $error->getMessage());
            }
            self::assertTrue($job->wasRevoked());
            self::assertSame([], $job->members());
        });
    }

    private static function php(string $code): array
    {
        $dir = dirname(__DIR__).'/Support';
        $load = 'require '.var_export($dir.'/BillingC3ProcessJob.php', true);
        $bootstrap = $load.'; require '.var_export($dir.'/BillingC3Race.php', true).'; require '
            .var_export($dir.'/BillingC3Process.php', true).'; require '.var_export($dir.'/BillingC3ProcessHandle.php', true).'; require '
            .var_export($dir.'/PaymentR2IntegrationIdentity.php', true).'; ';
        $code = $bootstrap.str_replace('JOB::', '\Tests\Support\BillingC3ProcessJob::', $code);

        return [PHP_BINARY, '-n', '-d', 'extension='.dirname(PHP_BINARY).'/ext/php_ffi.dll', '-d', 'ffi.enable=1', '-r',
            str_replace(["\r", "\n"], ' ', $code)];
    }

    private function withJob(callable $work): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! extension_loaded('ffi')) {
            self::markTestSkipped('Windows x64 PHP CLI with FFI is required.');
        }
        $token = bin2hex(random_bytes(32));
        putenv('SILANSIA_BILLING_C3_JOB='.$token);
        putenv('SYNTHETIC_TEST_PARENT='.getmypid());
        try {
            $work(Job::create($token));
        } finally {
            putenv('SILANSIA_BILLING_C3_JOB');
            putenv('SYNTHETIC_TEST_PARENT');
        }
    }
}
