<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvoiceHttpException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $kind, public readonly array $fields = [])
    {
        parent::__construct('Permintaan invoice tidak dapat diproses.');
    }
}
