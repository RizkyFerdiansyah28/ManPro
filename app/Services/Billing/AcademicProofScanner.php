<?php

declare(strict_types=1);

namespace App\Services\Billing;

use InvalidArgumentException;
use RuntimeException;

final class AcademicProofScanner
{
    public const LABEL = 'ACADEMIC SIMULATION — NOT REAL ANTIVIRUS';

    // Closed server-owned fixtures. The label is a simulation outcome, not a property of the PNG.
    private const PNG = [
        'clean' => '89504e470d0a1a0a0000000d49484452000000010000000108000000003a7e9b550000000d494441547801010200fdff00400042004120b099cb0000000049454e44ae426082',
        'infected' => '89504e470d0a1a0a0000000d49484452000000010000000108000000003a7e9b550000000d494441547801010200fdff008000820081c36e25e00000000049454e44ae426082',
        'unknown' => '89504e470d0a1a0a0000000d49484452000000010000000108000000003a7e9b550000000d494441547801010200fdff00c000c200c19ddbb1f90000000049454e44ae426082',
    ];

    /** Bytes for an authorized owned-runtime fixture to submit through the existing writer. */
    public static function fixtureBytes(string $scenario): string
    {
        $hex = self::PNG[$scenario] ?? throw new InvalidArgumentException('unknown_academic_fixture');

        return hex2bin($hex);
    }

    /** Simulate only when the claimed descriptor matches known synthetic bytes. */
    public function scan(array $assignment): string
    {
        $digest = $assignment['proof_sha256'] ?? null;
        $size = $assignment['proof_size_bytes'] ?? null;
        if (($assignment['is_simulation'] ?? null) !== true
            || ($assignment['scanner_identity'] ?? null) !== 'synthetic_mock'
            || ($assignment['stage_id'] ?? null) !== 'synthetic_stage_v1'
            || ($assignment['adapter_timeout_seconds'] ?? null) !== 5
            || ! is_string($assignment['proof_reference'] ?? null)
            || ! BillingTime::uuid($assignment['proof_reference'])
            || ! is_string($digest) || ! preg_match('/\A[a-f0-9]{64}\z/', $digest)
            || ! is_int($size) || $size < 1 || $size > 2097152
            || ($assignment['proof_mime'] ?? null) !== 'image/png') {
            throw new RuntimeException('academic_descriptor_rejected');
        }

        foreach (array_keys(self::PNG) as $scenario) {
            $bytes = self::fixtureBytes($scenario);
            if (hash_equals(hash('sha256', $bytes), $digest)
                && strlen($bytes) === $size
                && (getimagesizefromstring($bytes)['mime'] ?? null) === $assignment['proof_mime']) {
                return $scenario;
            }
        }

        throw new RuntimeException('academic_descriptor_rejected');
    }
}
