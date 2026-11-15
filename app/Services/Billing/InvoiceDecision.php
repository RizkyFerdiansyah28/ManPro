<?php

declare(strict_types=1);

namespace App\Services\Billing;

enum InvoiceDecision: string
{
    case Issue = 'ISSUE';
    case Hold = 'HOLD';
    case Skip = 'SKIP';
    case Reject = 'REJECT';
}
