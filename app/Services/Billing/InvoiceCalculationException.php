<?php

declare(strict_types=1);

namespace App\Services\Billing;

use InvalidArgumentException;

final class InvoiceCalculationException extends InvalidArgumentException
{
    public function __construct(public readonly InvoiceReason $reason)
    {
        parent::__construct('Snapshot kalkulasi tidak dapat diproses: '.$reason->value.'.');
    }
}
