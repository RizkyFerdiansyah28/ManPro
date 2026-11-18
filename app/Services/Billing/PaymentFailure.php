<?php

declare(strict_types=1);

namespace App\Services\Billing;

use RuntimeException;

final class PaymentFailure extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly ?string $sqlState = null, public readonly ?string $rule = null)
    {
        parent::__construct('Operasi pembayaran tidak dapat diselesaikan ('.$kind.').');
    }
}
