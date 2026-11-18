<?php

namespace Tests\Support;

use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\StoredPaymentProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Never registered by an application provider. Files are synthetic test data only.
final class PaymentProofFixtureStore extends PaymentProofStore
{
    private string $root;

    private array $created = [];

    public function __construct()
    {
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe(app(), DB::connection());
        $this->root = dirname($guard->identity['cache']).DIRECTORY_SEPARATOR.'p94b-proofs';
        if (! is_dir($this->root) && ! mkdir($this->root, 0700)) {
            throw new PaymentFailure('fixture_storage_failure');
        }
        if (is_link($this->root)) {
            throw new PaymentFailure('fixture_storage_failure');
        }
    }

    public function issue(string $submitter, string $invoice): string
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        $reference = (string) Str::uuid();
        $path = 'p94b-proofs/'.$reference.'.png';
        $this->created[] = $reference;
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z4gAAAABJRU5ErkJggg==', true);
        $proof = new StoredPaymentProof($path, hash('sha256', $bytes), strlen($bytes), 'image/png');
        if (file_put_contents($this->root.DIRECTORY_SEPARATOR.$reference.'.png', $bytes) !== strlen($bytes)
            || ! file_put_contents($this->root.DIRECTORY_SEPARATOR.$reference.'.json', json_encode($proof->row() + ['submitter' => $submitter, 'invoice' => $invoice], JSON_THROW_ON_ERROR))) {
            throw new PaymentFailure('fixture_storage_failure');
        }

        return $reference;
    }

    public function resolve(string $reference, string $submitter, string $invoice): StoredPaymentProof
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        if (! Str::isUuid($reference)) {
            throw new PaymentFailure('invalid_proof');
        }
        $file = $this->root.DIRECTORY_SEPARATOR.$reference.'.json';
        if (! is_file($file) || is_link($file)) {
            throw new PaymentFailure('invalid_proof');
        }
        $row = json_decode(file_get_contents($file), true);
        if (! is_array($row) || ($row['submitter'] ?? null) !== $submitter || ($row['invoice'] ?? null) !== $invoice) {
            throw new PaymentFailure('invalid_proof');
        }

        return new StoredPaymentProof($row['proof_path'], $row['proof_sha256'], $row['proof_size_bytes'], $row['proof_mime'], $row['proof_disk']);
    }

    public function assertStored(StoredPaymentProof $proof, string $submitter, string $invoice): void
    {
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        $reference = basename($proof->path, '.png');
        $known = $this->resolve($reference, $submitter, $invoice);
        $file = $this->root.DIRECTORY_SEPARATOR.$reference.'.png';
        if ($known->row() !== $proof->row() || ! is_file($file) || is_link($file)
            || filesize($file) !== $proof->size || hash_file('sha256', $file) !== $proof->sha256
            || (getimagesize($file)['mime'] ?? null) !== $proof->mime) {
            throw new PaymentFailure('proof_integrity_failure');
        }
    }

    public function cleanup(): void
    {
        // Only this instance's exact synthetic fixture files, never production retention.
        PostgresTestGuard::fromEnvironment()->assertSafe(app(), DB::connection());
        foreach ($this->created as $reference) {
            foreach (['png', 'json'] as $extension) {
                $file = $this->root.DIRECTORY_SEPARATOR.$reference.'.'.$extension;
                if (is_file($file) && ! is_link($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->root) && count(scandir($this->root)) === 2) {
            rmdir($this->root);
        }
    }
}
