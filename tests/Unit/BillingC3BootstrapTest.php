<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC3Bootstrap;

final class BillingC3BootstrapTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'silansia-h03-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->fixture));
    }

    protected function tearDown(): void
    {
        foreach (['autoload.php', 'bootstrap.php', 'package.json', 'package.mjs', 'php.ini', 'php_fake.dll', 'policy.json'] as $name) {
            $file = $this->fixture.DIRECTORY_SEPARATOR.$name;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->fixture);
    }

    public static function executableInputs(): array
    {
        return [['autoload.php'], ['bootstrap.php'], ['package.mjs'], ['php.ini'], ['php_fake.dll']];
    }

    #[DataProvider('executableInputs')]
    public function test_changed_bytes_fail_even_when_dependencies_report_the_same_version(string $name): void
    {
        file_put_contents($this->fixture.'/package.json', '{"version":"1.0.0"}');
        $target = $this->fixture.'/'.$name;
        file_put_contents($target, 'reviewed bytes');
        $digest = BillingC3Bootstrap::treeDigest($this->fixture);
        BillingC3Bootstrap::assertTree($this->fixture, $digest);
        file_put_contents($target, 'modified bytes');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_dependency_tree_mismatch');
        BillingC3Bootstrap::assertTree($this->fixture, $digest);
    }

    public function test_source_bootstrap_is_pinned_separately_from_the_dependency_trees(): void
    {
        $path = $this->fixture.'/bootstrap.php';
        file_put_contents($path, '<?php /* reviewed fixture */');
        $pins = ['bootstrap.php' => hash_file('sha256', $path)];
        BillingC3Bootstrap::assertSourcePins($this->fixture, $pins);
        file_put_contents($path, '<?php /* tampered fixture */');
        $this->expectExceptionMessage('billing_c3_dependency_source_pin_mismatch');
        BillingC3Bootstrap::assertSourcePins($this->fixture, $pins);
    }

    public function test_digest_rejects_a_missing_dependency(): void
    {
        $path = $this->fixture.'/autoload.php';
        file_put_contents($path, 'fixture');
        $digest = BillingC3Bootstrap::treeDigest($this->fixture);
        unlink($path);
        $this->expectExceptionMessage('billing_c3_dependency_tree_mismatch');
        BillingC3Bootstrap::assertTree($this->fixture, $digest);
    }

    public function test_dependency_verification_rejects_even_matching_bytes_after_deadline(): void
    {
        file_put_contents($this->fixture.'/autoload.php', 'reviewed bytes');
        $digest = BillingC3Bootstrap::treeDigest($this->fixture);
        $this->expectExceptionMessage('billing_c3_dependency_deadline_exceeded');
        BillingC3Bootstrap::assertTree($this->fixture, $digest, hrtime(true) - 1);
    }

    public function test_changed_policy_cannot_replace_a_digest_pinned_record(): void
    {
        $path = $this->fixture.'/policy.json';
        $reviewed = '{"version":1,"fixture":"reviewed"}';
        file_put_contents($path, $reviewed);
        $digest = hash('sha256', $reviewed);
        self::assertSame('reviewed', BillingC3Bootstrap::readPinnedJson($path, $digest)['fixture']);
        file_put_contents($path, '{"version":1,"fixture":"changed"}');
        $this->expectExceptionMessage('billing_c3_dependency_policy_digest_mismatch');
        BillingC3Bootstrap::readPinnedJson($path, $digest);
    }

    public function test_rejects_path_alias_before_reading_dependency_bytes(): void
    {
        $this->expectExceptionMessage('billing_c3_dependency_path_alias');
        BillingC3Bootstrap::treeDigest($this->fixture.'/../'.basename($this->fixture));
    }

    public static function loaderOverrides(): array
    {
        return array_map(static fn ($name) => [$name], ['PHP_INI_SCAN_DIR', 'phprc', 'NODE_OPTIONS', 'Node_Path', 'NODE_EXTRA_CA_CERTS']);
    }

    #[DataProvider('loaderOverrides')]
    public function test_rejects_loader_environment_overrides_even_when_present_but_blank(string $name): void
    {
        $this->expectExceptionMessage('billing_c3_boot_environment_override');
        BillingC3Bootstrap::assertEnvironment([$name => '']);
    }

    public function test_extension_profile_rejects_extra_module(): void
    {
        $this->expectExceptionMessage('billing_c3_php_extension_set_mismatch');
        BillingC3Bootstrap::assertExtensions([...get_loaded_extensions(), 'unexpected-fixture-module']);
    }

    public function test_native_module_cannot_be_sourced_from_an_outside_directory(): void
    {
        $path = $this->fixture.'/php_fake.dll';
        file_put_contents($path, 'synthetic library');
        BillingC3Bootstrap::assertNativeModulePath($path, $this->fixture, dirname(PHP_BINARY));
        $this->expectExceptionMessage('billing_c3_php_native_module_unverified');
        BillingC3Bootstrap::assertNativeModulePath($path, dirname(PHP_BINARY), dirname(PHP_BINARY).'/ext');
    }

    public function test_direct_control_is_denied_before_vendor_with_unapproved_ini_profile(): void
    {
        $launcher = dirname(__DIR__, 2).'/tests/Support/run-payment-r2-integration.php';
        $env = array_intersect_key(getenv(), array_flip(['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir']));
        $process = proc_open([PHP_BINARY, '-n', $launcher, '--billing-c3-control', 'migrate'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->fixture, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(78, proc_close($process));
        self::assertSame('', $stderr);
        self::assertSame(['status' => 'BLOCKED', 'reason' => 'billing_c3_php_boot_profile_mismatch'], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }
}
