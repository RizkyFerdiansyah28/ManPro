<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC3ClusterProcesses as Cluster;
use Tests\Support\BillingC3ProcessJob as Job;

final class BillingC3ClusterProcessesTest extends TestCase
{
    public function test_synthetic_wrapper_lineage_and_shutdown_in_the_windows_job(): void
    {
        $this->withFixture(static function (string $root, Job $job): void {
            self::assertSame(0, $job->supervise(self::command($root, 'primary'), $root, 25));
            self::assertTrue($job->wasRevoked());
            self::assertSame([], $job->members());
        });
    }

    public function test_timeout_and_revocation_leave_an_actual_wrapper_in_another_job_untouched(): void
    {
        $this->withFixture(static function (string $root, Job $job): void {
            $observer = proc_open(self::command($root, 'outside-supervisor'), [
                0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => STDERR,
            ], $pipes, $root, null, ['bypass_shell' => true]);
            self::assertIsResource($observer);
            try {
                self::awaitFile($root.'/outside.wrapper');
                try {
                    $job->supervise(self::command($root, 'timeout'), $root, 6);
                    self::fail('The timeout was accepted as a normal exit.');
                } catch (LogicException $error) {
                    self::assertSame('billing_c3_job_deadline_exceeded', $error->getMessage());
                }
                self::assertFileExists($root.'/timeout.covered');
                self::assertTrue($job->wasRevoked());
                self::assertSame([], $job->members());
                self::assertTrue(proc_get_status($observer)['running']);
                file_put_contents($root.'/outside.ping', 'ping');
                self::awaitFile($root.'/outside.pong');
            } finally {
                if (! is_file($root.'/outside.ping')) {
                    file_put_contents($root.'/outside.ping', 'ping');
                }
                file_put_contents($root.'/stop-worker-outside', 'stop');
                $deadline = Job::deadline(12);
                do {
                    $status = proc_get_status($observer);
                    if (! $status['running']) {
                        proc_close($observer);
                        break;
                    }
                    Job::assertDeadline($deadline, 'fixture_outside_observer_exit');
                    usleep(25_000);
                } while (true);
            }
            self::assertSame(0, $status['exitcode']);
        });
    }

    #[DataProvider('wrongLineages')]
    public function test_lineage_rejects_ambiguous_or_reused_identity(array $wrapperChanges, int $parent, array $masterChanges, int $masterParent): void
    {
        $launcher = ['pid' => 3, 'birth' => '0000000000000100'];
        $wrapper = ['pid' => 4, 'birth' => '0000000000000200', 'image' => 'C:\Windows\System32\cmd.exe'];
        $master = ['pid' => 5, 'birth' => '0000000000000300', 'image' => 'C:\private\synthetic.exe'];
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_cluster_lineage_mismatch');
        Cluster::assertLineage($launcher, '0000000000000400', array_replace($wrapper, $wrapperChanges), $parent,
            array_replace($master, $masterChanges), $masterParent, $wrapper['image'], $master['image']);
    }

    public static function wrongLineages(): array
    {
        return [
            'same name wrong path' => [['image' => 'C:\untrusted\cmd.exe'], 3, [], 4],
            'wrong pg_ctl parent' => [[], 8, [], 4],
            'wrong postmaster parent' => [[], 3, [], 8],
            'reused wrapper PID created after pg_ctl exited' => [['birth' => '0000000000000410'], 3, [], 4],
            'reused wrapper PID predates the launch' => [['birth' => '00000000000000ff'], 3, [], 4],
            'coordinator cannot double as wrapper' => [['pid' => 3], 3, [], 3],
            'postmaster born before wrapper' => [[], 3, ['birth' => '0000000000000190'], 4],
        ];
    }

    private static function command(string $root, string $mode): array
    {
        return [PHP_BINARY, '-n', '-d', 'extension='.dirname(PHP_BINARY).'/ext/php_ffi.dll', '-d', 'ffi.enable=1',
            dirname(__DIR__).'/Support/billing-c3-lineage-fixture.php', $root, $mode];
    }

    private static function awaitFile(string $file): void
    {
        $deadline = Job::deadline(12);
        do {
            Job::assertDeadline($deadline, 'fixture_marker_timeout');
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) > 0) {
                return;
            }
            usleep(25_000);
        } while (true);
    }

    private function withFixture(callable $work): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! extension_loaded('ffi')) {
            self::markTestSkipped('Windows x64 PHP CLI with FFI is required.');
        }
        $temp = realpath(sys_get_temp_dir());
        self::assertIsString($temp);
        $root = $temp.DIRECTORY_SEPARATOR.'silansia-c3-lineage-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($root));
        $token = bin2hex(random_bytes(32));
        $previous = getenv('SILANSIA_BILLING_C3_JOB');
        putenv('SILANSIA_BILLING_C3_JOB='.$token);
        try {
            $work($root, Job::create($token));
        } catch (LogicException $error) {
            $details = array_map(static fn (string $file): string => file_get_contents($file) ?: '', glob($root.'/error-*'));
            self::fail($error->getMessage().($details === [] ? '' : "\n".implode("\n", $details)));
        } finally {
            putenv($previous === false ? 'SILANSIA_BILLING_C3_JOB' : 'SILANSIA_BILLING_C3_JOB='.$previous);
            self::assertSame($root, realpath($root));
            $files = ['outside.wrapper', 'outside.ping', 'outside.pong', 'timeout.covered', 'worker-timeout',
                'worker-primary', 'worker-foreign', 'worker-outside', 'backend-primary', 'drain-primary',
                'stop-worker-primary', 'stop-worker-foreign', 'stop-worker-outside', 'stop-drain-primary'];
            foreach (glob($root.'/error-*') as $errorFile) {
                $file = basename($errorFile);
                self::assertMatchesRegularExpression('/\Aerror-[a-z-]+\z/', $file);
                $files[] = $file;
            }
            foreach ($files as $file) {
                $path = $root.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    self::assertTrue(unlink($path));
                }
            }
            self::assertTrue(rmdir($root));
        }
    }
}
