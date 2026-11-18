<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Closure;
use Illuminate\Http\UploadedFile;
use Throwable;

// Filesystem adapter; operational admission remains closed pending scanner/retention policy.
final class LocalPaymentProofStore extends PaymentProofStore
{
    public function __construct(private readonly PaymentProofEnvironment $environment, private readonly PaymentImageValidator $validator) {}

    public function intake(UploadedFile $file, string $reference, string $submitter, string $invoice): StoredPaymentProof
    {
        $this->environment->root();
        if (! $file->isValid() || $file->getSize() > 2097152) {
            throw new PaymentFailure('invalid_proof');
        }
        $bytes = $this->read($file->getPathname(), 2097152);
        $mime = $this->validator->validate($bytes, $file->getClientOriginalExtension());

        return $this->locked($reference, function (string $directory) use ($reference, $submitter, $invoice, $bytes, $mime) {
            $proof = new StoredPaymentProof('payment-proofs/'.$reference.'/object.'.($mime === 'image/png' ? 'png' : 'jpg'),
                hash('sha256', $bytes), strlen($bytes), $mime);
            $registry = $directory.'/registry.json';
            if (file_exists($registry)) {
                $known = $this->metadata($directory, $submitter, $invoice);
                if ($known->row() !== $proof->row()) {
                    throw new PaymentFailure('idempotency_conflict');
                }
                $this->verifyBytes($directory, $known);

                return $known;
            }
            // A partial publication is quarantined in place; never overwrite or silently repair it.
            if (file_exists($directory.'/object.png') || file_exists($directory.'/object.jpg')) {
                throw new PaymentFailure('proof_storage_unavailable');
            }
            $this->publish($directory.'/'.basename($proof->path), $bytes);
            $this->verifyBytes($directory, $proof);
            $this->publish($registry, json_encode($proof->row() + ['submitter' => $submitter, 'invoice' => $invoice], JSON_THROW_ON_ERROR));

            return $this->metadata($directory, $submitter, $invoice);
        });
    }

    public function resolve(string $reference, string $submitter, string $invoice): StoredPaymentProof
    {
        return $this->locked($reference, fn (string $directory) => $this->metadata($directory, $submitter, $invoice), false);
    }

    public function assertStored(StoredPaymentProof $proof, string $submitter, string $invoice): void
    {
        $reference = explode('/', $proof->path)[1] ?? '';
        $this->locked($reference, function (string $directory) use ($proof, $submitter, $invoice) {
            if ($this->metadata($directory, $submitter, $invoice)->row() !== $proof->row()) {
                throw new PaymentFailure('proof_integrity_failure');
            }
            $this->verifyBytes($directory, $proof);
        }, false);
    }

    public function bytes(StoredPaymentProof $proof, string $submitter, string $invoice): string
    {
        $reference = explode('/', $proof->path)[1] ?? '';

        return $this->locked($reference, function (string $directory) use ($proof, $submitter, $invoice) {
            if ($this->metadata($directory, $submitter, $invoice)->row() !== $proof->row()) {
                throw new PaymentFailure('proof_integrity_failure');
            }

            return $this->verifyBytes($directory, $proof);
        }, false);
    }

    // Internal operator inventory only; a complete object is not proof of SQL COMMIT.
    // No public endpoint, deletion, repair, relinking or paid-state mutation.
    public function inventory(): array
    {
        $root = $this->environment->root();
        $this->environment->confined($root);
        if (! is_dir($root)) {
            return [];
        }
        $rows = [];
        foreach (scandir($root) as $reference) {
            if (! BillingTime::uuid($reference)) {
                continue;
            }
            $rows[] = $this->locked($reference, function (string $directory) use ($reference) {
                foreach (['registry.json', 'object.png', 'object.jpg'] as $file) {
                    $this->environment->confined($directory.'/'.$file);
                }

                return ['reference' => $reference, 'registry_present' => is_file($directory.'/registry.json'),
                    'object_present' => is_file($directory.'/object.png') || is_file($directory.'/object.jpg'),
                    'disposition' => 'manual_review_required'];
            }, false);
        }

        return $rows;
    }

    private function locked(string $reference, Closure $body, bool $create = true): mixed
    {
        if (! BillingTime::uuid($reference)) {
            throw new PaymentFailure('invalid_proof');
        }
        $root = $this->environment->root();
        $directory = $root.'/'.strtolower($reference);
        $lock = null;
        try {
            foreach ([$root, $directory] as $path) {
                $this->environment->confined($path);
                if (! is_dir($path) && (! $create || (! @mkdir($path, 0700) && ! is_dir($path)))) {
                    throw new PaymentFailure('proof_storage_unavailable');
                }
                $this->environment->confined($path);
            }
            $lockPath = $directory.'/lock';
            $this->environment->confined($lockPath);
            $lock = @fopen($lockPath, $create ? 'c+b' : 'r+b');
            if (! $lock) {
                throw new PaymentFailure('proof_storage_unavailable');
            }
            $deadline = microtime(true) + 3;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new PaymentFailure('proof_storage_unavailable');
                }
                usleep(10000);
            }
            $this->environment->confined($directory);

            return $body($directory);
        } catch (PaymentFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new PaymentFailure('proof_storage_unavailable');
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function metadata(string $directory, string $submitter, string $invoice): StoredPaymentProof
    {
        $row = json_decode($this->read($directory.'/registry.json', 4096), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($row) || ($row['submitter'] ?? null) !== $submitter || ($row['invoice'] ?? null) !== $invoice) {
            throw new PaymentFailure('invalid_proof');
        }
        $proof = new StoredPaymentProof($row['proof_path'], $row['proof_sha256'], $row['proof_size_bytes'], $row['proof_mime'], $row['proof_disk']);
        if ($proof->path !== 'payment-proofs/'.basename($directory).'/object.'.($proof->mime === 'image/png' ? 'png' : 'jpg')) {
            throw new PaymentFailure('proof_integrity_failure');
        }

        return $proof;
    }

    private function verifyBytes(string $directory, StoredPaymentProof $proof): string
    {
        $bytes = $this->read($directory.'/'.basename($proof->path), 2097152);
        if (strlen($bytes) !== $proof->size || ! hash_equals($proof->sha256, hash('sha256', $bytes))
            || $this->validator->validate($bytes, pathinfo($proof->path, PATHINFO_EXTENSION)) !== $proof->mime) {
            throw new PaymentFailure('proof_integrity_failure');
        }

        return $bytes;
    }

    private function read(string $path, int $limit): string
    {
        $this->environment->confined($path);
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new PaymentFailure('proof_integrity_failure');
        }
        try {
            $stat = fstat($handle);
            if (($stat['mode'] & 0170000) !== 0100000) {
                throw new PaymentFailure('proof_integrity_failure');
            }
            $bytes = stream_get_contents($handle, $limit + 1);
            if ($bytes === false || strlen($bytes) > $limit) {
                throw new PaymentFailure('proof_integrity_failure');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    private function publish(string $path, string $bytes): void
    {
        $this->environment->confined($path);
        $handle = @fopen($path, 'x+b');
        if (! $handle) {
            throw new PaymentFailure('proof_storage_unavailable');
        }
        try {
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = fwrite($handle, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new PaymentFailure('proof_storage_unavailable');
                }
                $written += $count;
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new PaymentFailure('proof_storage_unavailable');
            }
        } finally {
            fclose($handle);
        }
    }
}
