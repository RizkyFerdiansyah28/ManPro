<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BillingC3Admission;
use Tests\Support\BillingC3WindowsHelpers;

final class BillingC3WindowsHelpersTest extends TestCase
{
    public function test_resolves_and_pins_the_three_native_helpers_without_executing_them(): void
    {
        $helpers = BillingC3WindowsHelpers::fromHost();
        $env = $helpers->environment(['TEMP' => 'fixture-temp', 'Path' => 'C:\\untrusted',
            'COMSPEC' => 'C:\\untrusted\\cmd.exe', 'psmodulepath' => 'C:\\untrusted']);

        self::assertSame('fixture-temp', $env['TEMP']);
        self::assertSame($env['SystemRoot'], $env['WINDIR']);
        self::assertSame($helpers->executable('cmd'), $env['ComSpec']);
        self::assertSame(dirname($helpers->executable('netstat')), $env['PATH']);
        self::assertSame(dirname($helpers->executable('powershell')).'\\Modules', $env['PSModulePath']);
        self::assertArrayNotHasKey('Path', $env);
        self::assertArrayNotHasKey('COMSPEC', $env);
        self::assertArrayNotHasKey('psmodulepath', $env);
    }

    public static function overrides(): array
    {
        return [
            'root override' => ['SystemRoot', 'C:\\untrusted-windows'],
            'case-insensitive Windows directory alias' => ['wInDiR', 'C:\\untrusted-windows'],
            'same executable basename in untrusted directory' => ['COMSPEC', 'C:\\untrusted-windows\\System32\\cmd.exe'],
            'relative shell' => ['ComSpec', '.\\cmd.exe'],
            'drive-relative Windows directory' => ['SYSTEMROOT', 'C:Windows'],
        ];
    }

    #[DataProvider('overrides')]
    public function test_rejects_windows_overrides(string $name, string $value): void
    {
        $helpers = BillingC3WindowsHelpers::fromHost();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_environment_override');
        $helpers->assertEnvironment([$name => $value]);
    }

    public function test_native_anchor_blocks_mutated_system_root_at_c3_admission(): void
    {
        $old = getenv('SystemRoot');
        putenv('SystemRoot=C:\\untrusted-windows');
        ob_start();
        try {
            $code = BillingC3Admission::entry(['--billing-c3-owned-child', 'C:\\unused-postgres']);
            $result = json_decode(ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
            putenv($old === false ? 'SystemRoot' : 'SystemRoot='.$old);
        }

        self::assertSame(78, $code);
        self::assertSame(['status' => 'BLOCKED', 'reason' => 'billing_c3_windows_environment_override'], $result);
    }

    public static function invalidPaths(): array
    {
        return [
            ['netstat.exe'],
            ['C:Windows\\netstat.exe'],
            ['C:\\WINDOWS\\System32\\..\\netstat.exe'],
            ['C:\\WINDOWS\\SYSTEM~1\\netstat.exe'],
            ['C:\\WINDOWS\\System32\\netstat.exe.'],
            ['\\\\?\\C:\\WINDOWS\\System32\\netstat.exe'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function test_rejects_relative_and_alias_paths_before_inspection(string $path): void
    {
        $helpers = BillingC3WindowsHelpers::fromHost();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_helper_path_invalid');
        $helpers->assertNativePath($path);
    }

    public function test_native_file_attributes_reject_a_missing_file(): void
    {
        $helpers = BillingC3WindowsHelpers::fromHost();
        $unavailable = dirname($helpers->executable('netstat')).'\\silansia-not-installed-'.bin2hex(random_bytes(8)).'.exe';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_helper_missing');
        $helpers->assertNativePath($unavailable);
    }

    public static function invalidMetadata(): array
    {
        return [
            'executable symlink or other file reparse' => [0x420, true, 'billing_c3_windows_helper_reparse'],
            'parent junction' => [0x410, false, 'billing_c3_windows_helper_reparse'],
            'directory in place of an executable' => [0x10, true, 'billing_c3_windows_helper_type'],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_rejects_reparse_metadata_at_any_component(int $attributes, bool $file, string $reason): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($reason);
        BillingC3WindowsHelpers::assertAttributes($attributes, $file);
    }

    public function test_wrong_same_name_executable_is_not_a_resolvable_helper(): void
    {
        $helpers = BillingC3WindowsHelpers::fromHost();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_helper_unlisted');
        $helpers->executable('C:\\untrusted\\netstat.exe');
    }

    public function test_rejects_digest_change_even_with_matching_binary_type(): void
    {
        $digest = hash('sha256', 'synthetic-reviewed-helper');
        BillingC3WindowsHelpers::assertExecutableIdentity(6, $digest, $digest);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_helper_hash_mismatch');
        BillingC3WindowsHelpers::assertExecutableIdentity(6, hash('sha256', 'synthetic-helper-changed'), $digest);
    }

    public function test_rejects_non_native_binary_even_with_matching_digest(): void
    {
        $digest = hash('sha256', 'synthetic-reviewed-helper');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_helper_binary_type');
        BillingC3WindowsHelpers::assertExecutableIdentity(0, $digest, $digest);
    }

    public static function unverifiableAutoRun(): array
    {
        return [[0], [5]]; // Value exists or access denied.
    }

    #[DataProvider('unverifiableAutoRun')]
    public function test_cmd_autorun_requires_proven_absence(int $status): void
    {
        BillingC3WindowsHelpers::assertAutoRunStatus(2);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('billing_c3_windows_cmd_autorun_unverified');
        BillingC3WindowsHelpers::assertAutoRunStatus($status);
    }
}
