<?php

declare(strict_types=1);

namespace App\Services\Billing;

final class PaymentProofEnvironment
{
    public function __construct(private readonly PaymentReadiness $readiness) {}

    public function root(): string
    {
        // S1 approval is synthetic/owned testing only. Never open a development disk.
        if (! app()->environment('testing') || config('payment_http.enabled') !== true
            || config('payment_http.synthetic_validation') !== true) {
            throw new PaymentFailure('proof_boundary_unavailable');
        }
        $this->readiness->entry();
        $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '', true, 16, JSON_THROW_ON_ERROR);
        $parent = dirname($identity['cache']);
        // Windows sys_get_temp_dir() may use an 8.3 alias. Check every original
        // ancestor for links before canonicalizing that trusted runner directory.
        $cursor = $parent;
        while (true) {
            if (is_link($cursor)) {
                throw new PaymentFailure('proof_storage_unavailable');
            }
            $next = dirname($cursor);
            if ($next === $cursor) {
                break;
            }
            $cursor = $next;
        }
        $parent = realpath($parent);
        if ($parent === false) {
            throw new PaymentFailure('proof_storage_unavailable');
        }
        $this->confined($parent);

        return $parent.DIRECTORY_SEPARATOR.'p94c-proofs';
    }

    public function confined(string $path): void
    {
        $cursor = $path;
        while (true) {
            if (is_link($cursor)) {
                throw new PaymentFailure('proof_storage_unavailable');
            }
            if (file_exists($cursor)) {
                $actual = realpath($cursor);
                $normalize = static fn (string $value) => str_replace('\\', '/', $value);
                if ($actual === false || strcasecmp($normalize($actual), $normalize($cursor)) !== 0) {
                    throw new PaymentFailure('proof_storage_unavailable');
                }
            }
            $next = dirname($cursor);
            if ($cursor === $next) {
                break;
            }
            $cursor = $next;
        }
    }
}
