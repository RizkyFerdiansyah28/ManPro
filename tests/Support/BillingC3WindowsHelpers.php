<?php

declare(strict_types=1);

namespace Tests\Support;

use FFI;
use FFI\CData;
use LogicException;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

/** Host-specific helper pins for C3. A Windows update requires a new review. */
final class BillingC3WindowsHelpers
{
    private const TOOLS = [
        'powershell' => ['WindowsPowerShell\\v1.0\\powershell.exe', '95ce2acbc6cd9d7f4a22759d39a8d40fc2a53ceeecf068ef856849f0d2076961'],
        'netstat' => ['netstat.exe', 'b5159cbf8739b421beec5baf1c5e60e8e5ea36b425e4374f05d95910d7fb49f3'],
        'cmd' => ['cmd.exe', 'ccd79a7568ea9240ccd6fe124807b9d3e4dbc3a4cf92954299ac3029f4a91d02'],
    ];

    private const SYSTEM_SETTINGS = ['SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATH', 'PSMODULEPATH'];

    private const POWERSHELL_MODULES = 'WindowsPowerShell\\v1.0\\Modules';

    private const CAPACITY = 512;

    private function __construct(private readonly FFI $kernel, private readonly string $windows, private readonly string $system) {}

    public static function fromHost(): self
    {
        Identity::require(PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8 && class_exists(FFI::class, false), 'billing_c3_windows_native_api_required');
        try {
            // kernel32 is loaded by the Windows loader before this script. Verify the loaded module's path as well.
            $kernel = FFI::cdef(<<<'C'
                uint32_t GetSystemDirectoryW(uint16_t *buffer, uint32_t size);
                uint32_t GetSystemWindowsDirectoryW(uint16_t *buffer, uint32_t size);
                void *GetModuleHandleW(const uint16_t *name);
                uint32_t GetModuleFileNameW(void *module, uint16_t *buffer, uint32_t size);
                uint32_t GetFileAttributesW(const uint16_t *path);
                uint32_t GetLongPathNameW(const uint16_t *path, uint16_t *buffer, uint32_t size);
                int GetBinaryTypeW(const uint16_t *path, uint32_t *type);
                C, 'kernel32.dll');
            $buffer = $kernel->new('uint16_t['.self::CAPACITY.']');
            $system = self::decode($kernel, $buffer, $kernel->GetSystemDirectoryW($buffer, self::CAPACITY));
            $windows = self::decode($kernel, $buffer, $kernel->GetSystemWindowsDirectoryW($buffer, self::CAPACITY));
            self::assertAbsolute($windows);
            Identity::require(strcasecmp($system, $windows.'\\System32') === 0, 'billing_c3_windows_system_path_mismatch');
            $handle = $kernel->GetModuleHandleW(self::wide($kernel, 'kernel32.dll'));
            Identity::require($handle !== null && ! FFI::isNull($handle), 'billing_c3_windows_native_api_required');
            $module = self::decode($kernel, $buffer, $kernel->GetModuleFileNameW($handle, $buffer, self::CAPACITY));
            Identity::require(strcasecmp($module, $system.'\\kernel32.dll') === 0, 'billing_c3_windows_system_path_mismatch');
            $helpers = new self($kernel, $windows, $system);
            $helpers->assertNativePath($module);
            $helpers->assertEnvironment(getenv());
            $helpers->assertNativePath($system.'\\'.self::POWERSHELL_MODULES, false);
            foreach (array_keys(self::TOOLS) as $tool) {
                $helpers->executable($tool);
            }
            $helpers->assertCmdAutoRunAbsent();

            return $helpers;
        } catch (\FFI\Exception $error) {
            throw new LogicException('billing_c3_windows_native_api_required', 0, $error);
        }
    }

    /** Accept only consistent Windows aliases; PATH/module overrides are discarded when building the child environment. */
    public function assertEnvironment(array $environment): void
    {
        foreach ($environment as $key => $value) {
            $expected = match (strtoupper((string) $key)) {
                'SYSTEMROOT', 'WINDIR' => $this->windows,
                'COMSPEC' => $this->system.'\\cmd.exe',
                default => null,
            };
            if ($expected !== null) {
                self::assertExactPath($value, $expected, 'billing_c3_windows_environment_override');
            }
        }
    }

    public function environment(array $extra): array
    {
        // Do not inherit any OS executable, module-search or shell settings from the caller.
        $safe = [];
        foreach ($extra as $key => $value) {
            if (! in_array(strtoupper((string) $key), self::SYSTEM_SETTINGS, true)) {
                $safe[$key] = $value;
            }
        }
        $modules = $this->system.'\\'.self::POWERSHELL_MODULES;
        $this->assertNativePath($modules, false);

        return $safe + ['SystemRoot' => $this->windows, 'WINDIR' => $this->windows,
            'ComSpec' => $this->executable('cmd'), 'PATH' => $this->system,
            'PSModulePath' => $modules];
    }

    public function executable(string $name): string
    {
        Identity::require(isset(self::TOOLS[$name]), 'billing_c3_windows_helper_unlisted');
        [$relative, $expectedHash] = self::TOOLS[$name];
        $path = $this->system.'\\'.$relative;
        $this->assertNativePath($path);
        $type = $this->kernel->new('uint32_t');
        $valid = $this->kernel->GetBinaryTypeW(self::wide($this->kernel, $path), FFI::addr($type)) !== 0;
        self::assertExecutableIdentity($valid ? $type->cdata : null, @hash_file('sha256', $path), $expectedHash);

        return $path;
    }

    /** pg_ctl launches cmd /C without /D. Deny a registry startup command without reading or running it. */
    public function assertCmdAutoRunAbsent(): void
    {
        $library = $this->system.'\\advapi32.dll';
        $this->assertNativePath($library);
        try {
            $registry = FFI::cdef('int32_t RegGetValueW(void *, const uint16_t *, const uint16_t *, uint32_t, uint32_t *, void *, uint32_t *);', $library);
            $key = self::wide($registry, 'Software\\Microsoft\\Command Processor');
            $name = self::wide($registry, 'AutoRun');
            foreach ([-2147483647, -2147483646] as $handle) { // HKEY_CURRENT_USER, HKEY_LOCAL_MACHINE.
                $size = $registry->new('uint32_t');
                $status = $registry->RegGetValueW($registry->cast('void *', $handle), $key, $name,
                    0x10000000 | 0x0000ffff, null, null, FFI::addr($size)); // RRF_NOEXPAND | RRF_RT_ANY.
                self::assertAutoRunStatus($status);
            }
        } catch (\FFI\Exception $error) {
            throw new LogicException('billing_c3_windows_native_api_required', 0, $error);
        }
    }

    public function assertNativePath(string $path, bool $file = true): void
    {
        self::assertAbsolute($path);
        clearstatcache(true);
        for ($cursor = $path; strlen($cursor) > 3; $cursor = dirname($cursor)) {
            self::assertAttributes($this->kernel->GetFileAttributesW(self::wide($this->kernel, $cursor)), $file && $cursor === $path);
        }
        $buffer = $this->kernel->new('uint16_t['.self::CAPACITY.']');
        $size = $this->kernel->GetLongPathNameW(self::wide($this->kernel, $path), $buffer, self::CAPACITY);
        $long = self::decode($this->kernel, $buffer, $size);
        $real = realpath($path);
        self::assertExactPath($long, $path, 'billing_c3_windows_helper_path_alias');
        self::assertExactPath($real, $path, 'billing_c3_windows_helper_path_alias');
    }

    public static function assertAttributes(int $attributes, bool $file): void
    {
        Identity::require($attributes !== 0xffffffff, 'billing_c3_windows_helper_missing');
        Identity::require(($attributes & 0x400) === 0, 'billing_c3_windows_helper_reparse');
        Identity::require((($attributes & 0x10) === 0) === $file, 'billing_c3_windows_helper_type');
    }

    public static function assertExecutableIdentity(?int $type, string|false $digest, string $expected): void
    {
        Identity::require($type === 6, 'billing_c3_windows_helper_binary_type'); // SCS_64BIT_BINARY.
        Identity::require(is_string($digest) && hash_equals($expected, $digest), 'billing_c3_windows_helper_hash_mismatch');
    }

    public static function assertAutoRunStatus(int $status): void
    {
        Identity::require($status === 2, 'billing_c3_windows_cmd_autorun_unverified'); // ERROR_FILE_NOT_FOUND.
    }

    private static function assertExactPath(string|false $actual, string $expected, string $reason): void
    {
        Identity::require(is_string($actual) && strcasecmp($actual, $expected) === 0, $reason);
    }

    private static function assertAbsolute(string $path): void
    {
        Identity::require(preg_match('~\A[A-Za-z]:\\\\[A-Za-z0-9_-][A-Za-z0-9_. -]*(?:\\\\[A-Za-z0-9_-][A-Za-z0-9_. -]*)*\z~', $path) === 1
            && ! preg_match('~[ .](?:\\\\|\z)~', $path), 'billing_c3_windows_helper_path_invalid');
    }

    private static function wide(FFI $api, string $value): CData
    {
        $encoded = iconv('UTF-8', 'UTF-16LE', $value."\0");
        Identity::require(is_string($encoded), 'billing_c3_windows_helper_path_invalid');
        $buffer = $api->new('uint16_t['.intdiv(strlen($encoded), 2).']');
        FFI::memcpy($buffer, $encoded, strlen($encoded));

        return $buffer;
    }

    private static function decode(FFI $api, CData $buffer, int $size): string
    {
        Identity::require($size > 0 && $size < self::CAPACITY, 'billing_c3_windows_system_path_unavailable');
        $path = iconv('UTF-16LE', 'UTF-8', FFI::string($api->cast('char *', FFI::addr($buffer[0])), $size * 2));
        Identity::require(is_string($path), 'billing_c3_windows_system_path_unavailable');

        return $path;
    }
}
