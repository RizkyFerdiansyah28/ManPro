<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

/** The explicitly reviewed C2 snapshot. The process that wrote the log and the historical index byte drift are unknown. */
final class BillingC2Baseline
{
    public const APPROVED = [
        'storage/logs/laravel.log' => 'd107d0ea988ce8650926b45bf354864f74a43175220f224ecfebbb04bf2c0004',
        'resources/js/components/Payments/PaymentPages.jsx' => '09d653a67e32e0e7a813ff1663399542cd2bf404e52c06bee6534c9b401b36dc',
        'tests/Frontend/paymentComponents.test.mjs' => '29768d286c5223e7e12b25df2f14d69d157654a6437d18231aef3882540812cd',
        '.git/index' => '3cd3d6e2c2ece28f22946932d818444ecf66f3c623f531249901052193cbcaad',
    ];

    private const STORAGE_ARCHIVE = 'docs/report/SILANSIA_P9_4E_1_BASELINE.json';

    private const STORAGE_ARCHIVE_SHA256 = '53557fb40f18c56797b6e6ebad055e57d7c3df3d509bab40899ee4db62c27149';

    public static function sourcePins(): array
    {
        return array_replace(Identity::PINS, array_intersect_key(self::APPROVED, Identity::PINS));
    }

    public static function verify(string $base): array
    {
        clearstatcache(true);
        Identity::pins($base, self::APPROVED);
        Identity::pins($base, self::sourcePins());
        Identity::pins($base, [self::STORAGE_ARCHIVE => self::STORAGE_ARCHIVE_SHA256]);
        $archive = json_decode(ltrim(file_get_contents($base.'/'.self::STORAGE_ARCHIVE), "\xef\xbb\xbf"), true, flags: JSON_THROW_ON_ERROR);
        $snapshot = Runtime::protectedSnapshot();
        $expected = [];
        $prefix = str_replace('\\', '/', $base).'/';
        foreach ($archive['protected_storage'] as $entry) {
            $path = str_replace('\\', '/', $entry['path']);
            Identity::require(str_starts_with($path, $prefix), 'billing_c2_archive_root_mismatch');
            $relative = substr($path, strlen($prefix));
            $expected[$relative] = self::APPROVED[$relative] ?? strtolower($entry['sha256']);
        }
        Identity::require(count($expected) === 123, 'billing_c2_storage_archive_incomplete');
        foreach ($expected as $path => $hash) {
            Identity::require(($snapshot[$path]['sha256'] ?? null) === $hash, 'billing_c2_storage_hash_mismatch');
        }
        // Preserve all current paths during this invocation, including files outside the historical archive.
        foreach ($snapshot as $path => $entry) {
            Identity::require($entry['type'] !== 'link', 'billing_c2_protected_link_detected');
            Runtime::canonical($base.'/'.str_replace('/', DIRECTORY_SEPARATOR, $path));
        }

        return $snapshot;
    }
}
