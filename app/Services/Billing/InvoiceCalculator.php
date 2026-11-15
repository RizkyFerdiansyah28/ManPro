<?php

declare(strict_types=1);

namespace App\Services\Billing;

class InvoiceCalculator
{
    public const POLICY_VERSION = 'calendar-wib-daily-half-up-v1';

    public function calculate(InvoiceCalculationInput $input): InvoiceCalculationResult
    {
        if ($input->policyVersion !== self::POLICY_VERSION) {
            throw new InvoiceCalculationException(InvoiceReason::UnsupportedPolicy);
        }
        [$monthStart, $monthEnd, $daysInMonth] = BillingTime::month($input->billingMonth);
        $started = BillingTime::instant($input->startedAt);
        $ended = $input->endedAt === null ? null : BillingTime::instant($input->endedAt);
        if ($ended !== null && $ended <= $started) {
            throw new InvoiceCalculationException(InvoiceReason::InvalidInterval);
        }
        [$rate, $minor] = $this->rate($input->monthlyPrice);
        $a = max($started, $monthStart);
        $b = $ended === null ? $monthEnd : min($ended, $monthEnd);
        $days = 0;
        for ($day = $monthStart; $day < $monthEnd; $day = $next) {
            $next = $day->modify('+1 day');
            if ($a < $b && $day < $b && $next > $a) {
                $days++;
            }
        }
        $amount = null;
        if ($days > 0) {
            if ($minor > intdiv($this->maximumInteger(), $days)) {
                throw new InvoiceCalculationException(InvoiceReason::Overflow);
            }
            $product = $minor * $days;
            $rounded = intdiv($product, $daysInMonth) + (2 * ($product % $daysInMonth) >= $daysInMonth ? 1 : 0);
            $amount = intdiv($rounded, 100).'.'.str_pad((string) ($rounded % 100), 2, '0', STR_PAD_LEFT);
        }

        return new InvoiceCalculationResult($input->billingMonth, $days > 0 ? BillingTime::format($a) : null,
            $days > 0 ? BillingTime::format($b) : null, $days, $daysInMonth, $rate, $amount, self::POLICY_VERSION);
    }

    /** @return array{string, int} */
    private function rate(string|int|float|bool|null $rate): array
    {
        if ($rate === null) {
            throw new InvoiceCalculationException(InvoiceReason::NullRate);
        }
        if (! is_string($rate) || ! preg_match('/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?\z/', $rate, $parts)) {
            throw new InvoiceCalculationException(InvoiceReason::InvalidRate);
        }
        // Tidak ada cast nominal sebelum runtime exact divalidasi.
        if ($this->integerBytes() !== 8) {
            throw new InvoiceCalculationException(InvoiceReason::UnsupportedRuntime);
        }
        $fraction = str_pad($parts[2] ?? '', 2, '0');
        $minor = (int) ($parts[1].$fraction);

        return [$parts[1].'.'.$fraction, $minor];
    }

    // Test seams capability saja, bukan policy/config/clock yang mengubah hasil finansial.
    protected function integerBytes(): int
    {
        return PHP_INT_SIZE;
    }

    protected function maximumInteger(): int
    {
        return PHP_INT_MAX;
    }
}
