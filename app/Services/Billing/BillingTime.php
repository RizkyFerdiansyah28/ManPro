<?php

declare(strict_types=1);

namespace App\Services\Billing;

use DateTimeImmutable;
use DateTimeZone;

final class BillingTime
{
    /** @return array{DateTimeImmutable, DateTimeImmutable, int} */
    public static function month(string $month): array
    {
        if (! preg_match('/\A(?!0000)[0-9]{4}-[0-9]{2}-01\z/', $month)) {
            throw new InvoiceCalculationException(InvoiceReason::InvalidMonth);
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month, new DateTimeZone('Asia/Jakarta'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($start === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $start->format('Y-m-d') !== $month) {
            throw new InvoiceCalculationException(InvoiceReason::InvalidMonth);
        }

        return [$start, $start->modify('+1 month'), (int) $start->format('t')];
    }

    public static function instant(string $value): DateTimeImmutable
    {
        if (! preg_match('/\A((?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2})[T ]([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?(Z|[+-](?:(?:0[0-9]|1[0-3])(?::[0-5][0-9])?|14(?::00)?))\z/', $value, $parts)) {
            throw new InvoiceCalculationException(InvoiceReason::InvalidInstant);
        }
        $offset = $parts[4] === 'Z' ? '+00:00' : $parts[4];
        if (strlen($offset) === 3) {
            $offset .= ':00';
        }
        $normalized = $parts[1].' '.$parts[2].'.'.str_pad($parts[3], 6, '0').$offset;
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $normalized, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($instant === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $instant->format('Y-m-d H:i:s.uP') !== $normalized) {
            throw new InvoiceCalculationException(InvoiceReason::InvalidInstant);
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public static function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function uuid(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id) === 1;
    }
}
