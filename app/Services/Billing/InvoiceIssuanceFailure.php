<?php

declare(strict_types=1);

namespace App\Services\Billing;

use RuntimeException;

final class InvoiceIssuanceFailure extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $sqlState = null,
        public readonly ?string $rule = null,
    ) {
        // Jangan meneruskan SQL, bindings, atau pesan PDO ke response/log aplikasi.
        parent::__construct('Penerbitan invoice ditolak: '.$kind.'.');
    }
}
