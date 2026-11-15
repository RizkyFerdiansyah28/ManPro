<?php

declare(strict_types=1);

namespace App\Services\Billing;

use DateTimeZone;

final class InvoiceDueDates
{
    /** @return array{string,string} */
    public static function fromIssuedAt(string $at): array
    {
        $day = BillingTime::instant($at)->setTimezone(new DateTimeZone('Asia/Jakarta'))->setTime(0, 0);

        return [$day->modify('+7 days')->format('Y-m-d'), $day->modify('+10 days')->format('Y-m-d')];
    }
}
