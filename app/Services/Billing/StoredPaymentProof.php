<?php

declare(strict_types=1);

namespace App\Services\Billing;

// Metadata is not a capability. PaymentService only obtains it through its trusted store.
final readonly class StoredPaymentProof
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $size,
        public string $mime,
        public string $disk = 'local',
    ) {
        if ($disk !== 'local' || ! in_array($mime, ['image/jpeg', 'image/png'], true)
            || $size < 1 || $size > 2097152 || ! preg_match('/\A[0-9a-f]{64}\z/', $sha256)
            || $path === '' || strlen($path) > 512 || ! preg_match('~\A[a-zA-Z0-9_/-]+\.(?:png|jpg)\z~', $path)
            || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new PaymentFailure('invalid_proof');
        }
    }

    public function row(): array
    {
        return ['proof_path' => $this->path, 'proof_sha256' => $this->sha256, 'proof_size_bytes' => $this->size,
            'proof_mime' => $this->mime, 'proof_disk' => $this->disk];
    }

    public static function fromRow(object $row): self
    {
        return new self($row->proof_path, $row->proof_sha256, (int) $row->proof_size_bytes, $row->proof_mime, $row->proof_disk);
    }
}
