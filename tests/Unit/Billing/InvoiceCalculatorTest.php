<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\InvoiceCalculationException;
use App\Services\Billing\InvoiceCalculationInput;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceReason;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private function input(array $changes = []): InvoiceCalculationInput
    {
        return new InvoiceCalculationInput(...array_replace([
            'billingMonth' => '2028-01-01', 'startedAt' => '2028-01-01T00:00:00+07:00', 'endedAt' => null,
            'monthlyPrice' => '3100000.00', 'policyVersion' => InvoiceCalculator::POLICY_VERSION,
        ], $changes));
    }

    public static function vectors(): array
    {
        return [
            'jan-full' => [[], 31, 31, '3100000.00'],
            'jan-middle' => [['startedAt' => '2028-01-16T12:00:00+07:00'], 16, 31, '1600000.00'],
            'jan-last' => [['startedAt' => '2028-01-31T23:00:00+07:00'], 1, 31, '100000.00'],
            'closed-afternoon' => [['endedAt' => '2028-01-20T15:00:00+07:00'], 20, 31, '2000000.00'],
            'exclusive-midnight' => [['endedAt' => '2028-01-20T00:00:00+07:00'], 19, 31, '1900000.00'],
            'feb-normal' => [['billingMonth' => '2027-02-01', 'startedAt' => '2027-02-15T00:00:00+07:00', 'monthlyPrice' => '2800000.00'], 14, 28, '1400000.00'],
            'feb-leap' => [['billingMonth' => '2028-02-01', 'startedAt' => '2028-02-15T00:00:00+07:00', 'monthlyPrice' => '2900000.00'], 15, 29, '1500000.00'],
            'feb-last' => [['billingMonth' => '2028-02-01', 'startedAt' => '2028-02-29T10:00:00+07:00', 'monthlyPrice' => '2900000.00'], 1, 29, '100000.00'],
            'april' => [['billingMonth' => '2028-04-01', 'startedAt' => '2028-04-16T00:00:00+07:00', 'monthlyPrice' => '3000000.00'], 15, 30, '1500000.00'],
            'fraction' => [['startedAt' => '2028-01-31T00:00:00+07:00', 'monthlyPrice' => '1000.00'], 1, 31, '32.26'],
            'tie' => [['billingMonth' => '2027-02-01', 'startedAt' => '2027-02-28T00:00:00+07:00', 'monthlyPrice' => '0.14'], 1, 28, '0.01'],
            'rounded-zero' => [['startedAt' => '2028-01-31T00:00:00+07:00', 'monthlyPrice' => '0.01'], 1, 31, '0.00'],
            'zero' => [['monthlyPrice' => '0.00'], 31, 31, '0.00'],
            'max-full' => [['monthlyPrice' => '999999999999.99'], 31, 31, '999999999999.99'],
            'max-one-day' => [['startedAt' => '2028-01-31T00:00:00+07:00', 'monthlyPrice' => '999999999999.99'], 1, 31, '32258064516.13'],
            'same-day' => [['startedAt' => '2028-01-10T10:00:00+07:00', 'endedAt' => '2028-01-10T10:01:00+07:00'], 1, 31, '100000.00'],
            'starts-at-m1' => [['startedAt' => '2028-02-01T00:00:00+07:00'], 0, 31, null],
            'ends-at-m0' => [['startedAt' => '2027-12-01T00:00:00+07:00', 'endedAt' => '2028-01-01T00:00:00+07:00'], 0, 31, null],
            'utc-in-next-wib-month' => [['billingMonth' => '2028-02-01', 'startedAt' => '2028-02-29T18:00:00Z'], 0, 29, null],
            'one-microsecond-before-m1' => [['startedAt' => '2028-01-31T23:59:59.999999+07:00'], 1, 31, '100000.00'],
            'two-microseconds-across-days' => [['startedAt' => '2028-01-01T23:59:59.999999+07:00', 'endedAt' => '2028-01-02T00:00:00.000001+07:00'], 2, 31, '200000.00'],
            'clip-both-ends' => [['startedAt' => '2027-12-01T00:00:00Z', 'endedAt' => '2028-03-01T00:00:00Z'], 31, 31, '3100000.00'],
            'century-not-leap' => [['billingMonth' => '1900-02-01', 'startedAt' => '1899-01-01T00:00:00Z', 'monthlyPrice' => '2800.00'], 28, 28, '2800.00'],
            'century-leap' => [['billingMonth' => '2000-02-01', 'startedAt' => '1999-01-01T00:00:00Z', 'monthlyPrice' => '2900.00'], 29, 29, '2900.00'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_exact_calendar_vectors(array $changes, int $days, int $monthDays, ?string $amount): void
    {
        $input = $this->input($changes);
        $before = serialize($input);
        $calculator = new InvoiceCalculator;
        $result = $calculator->calculate($input);
        $this->assertSame($days, $result->chargeableDays);
        $this->assertSame($monthDays, $result->daysInMonth);
        $this->assertSame($amount, $result->amount);
        $this->assertSame($input->billingMonth, $result->billingMonth);
        $this->assertSame(InvoiceCalculator::POLICY_VERSION, $result->policyVersion);
        $this->assertSame($before, serialize($input));
        $this->assertEquals($result, $calculator->calculate($input));
        if ($days === 0) {
            $this->assertNull($result->effectiveStartedAt);
            $this->assertNull($result->effectiveEndedAt);
        } else {
            $this->assertMatchesRegularExpression('/\.\d{6}Z\z/', $result->effectiveStartedAt);
            $this->assertMatchesRegularExpression('/\.\d{6}Z\z/', $result->effectiveEndedAt);
        }
    }

    public static function invalidRates(): array
    {
        return array_map(fn ($value) => [$value], [null, -1, 1, true, false, 1.25, NAN, INF, -INF,
            '', ' ', ' 1.00', '1.00 ', '-0.00', '-1.00', '+1.00', '1e3', 'NaN', 'Infinity',
            '1,000.00', 'Rp1.00', '001.00', '.50', '1.', '0.001', '1000000000000.00', '999999999999.999']);
    }

    #[DataProvider('invalidRates')]
    public function test_invalid_money_never_becomes_a_nominal(string|int|float|bool|null $rate): void
    {
        try {
            (new InvoiceCalculator)->calculate($this->input(['monthlyPrice' => $rate]));
            $this->fail('Tarif invalid tidak boleh menjadi nominal.');
        } catch (InvoiceCalculationException $exception) {
            $this->assertSame($rate === null ? InvoiceReason::NullRate : InvoiceReason::InvalidRate, $exception->reason);
        }
    }

    public static function invalidContext(): array
    {
        return [
            [['billingMonth' => '2028-01-02'], InvoiceReason::InvalidMonth],
            [['billingMonth' => '2028-13-01'], InvoiceReason::InvalidMonth],
            [['billingMonth' => '0000-01-01'], InvoiceReason::InvalidMonth],
            [['billingMonth' => '2028-1-01'], InvoiceReason::InvalidMonth],
            [['billingMonth' => 'tomorrow'], InvoiceReason::InvalidMonth],
            [['endedAt' => '2028-01-01T00:00:00+07:00'], InvoiceReason::InvalidInterval],
            [['endedAt' => '2027-12-31T23:59:59+07:00'], InvoiceReason::InvalidInterval],
            [['startedAt' => '2028-02-30T00:00:00Z'], InvoiceReason::InvalidInstant],
            [['startedAt' => '2028-01-01T24:00:00Z'], InvoiceReason::InvalidInstant],
            [['startedAt' => '2028-01-01T00:00:60Z'], InvoiceReason::InvalidInstant],
            [['startedAt' => '2028-01-01T00:00:00'], InvoiceReason::InvalidInstant],
            [['startedAt' => '2028-01-01'], InvoiceReason::InvalidInstant],
            [['startedAt' => 'now'], InvoiceReason::InvalidInstant],
            [['startedAt' => '2028-01-01T00:00:00.1234567Z'], InvoiceReason::InvalidInstant],
            [['startedAt' => '2028-01-01T00:00:00+14:01'], InvoiceReason::InvalidInstant],
            [['policyVersion' => 'future-v2'], InvoiceReason::UnsupportedPolicy],
        ];
    }

    #[DataProvider('invalidContext')]
    public function test_invalid_context_has_exact_reason(array $changes, InvoiceReason $reason): void
    {
        try {
            (new InvoiceCalculator)->calculate($this->input($changes));
            $this->fail('Context invalid harus ditolak.');
        } catch (InvoiceCalculationException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_capability_and_multiplication_fail_closed_without_float_fallback(): void
    {
        $unsupported = new class extends InvoiceCalculator
        {
            protected function integerBytes(): int
            {
                return 4;
            }
        };
        $overflow = new class extends InvoiceCalculator
        {
            protected function maximumInteger(): int
            {
                return 100;
            }
        };
        foreach ([[$unsupported, InvoiceReason::UnsupportedRuntime], [$overflow, InvoiceReason::Overflow]] as [$calculator, $reason]) {
            try {
                $calculator->calculate($this->input());
                $this->fail('Runtime tidak memadai harus gagal sebelum perkalian.');
            } catch (InvoiceCalculationException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_timezone_default_does_not_change_results_and_microseconds_are_preserved(): void
    {
        $input = $this->input(['startedAt' => '2028-01-16 05:00:00.123456+00', 'monthlyPrice' => '1234.5']);
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $one = (new InvoiceCalculator)->calculate($input);
            date_default_timezone_set('Pacific/Auckland');
            $two = (new InvoiceCalculator)->calculate($input);
        } finally {
            date_default_timezone_set($original);
        }
        $this->assertEquals($one, $two);
        $this->assertSame('2028-01-16T05:00:00.123456Z', $one->effectiveStartedAt);
        $this->assertSame('2028-01-31T17:00:00.000000Z', $one->effectiveEndedAt);
        $this->assertSame('1234.50', $one->monthlyRate);
    }

    public function test_dtos_are_readonly_and_only_the_explicit_rate_is_used(): void
    {
        $input = $this->input();
        $result = (new InvoiceCalculator)->calculate($input);
        $this->assertSame('3100000.00', $result->monthlyRate);
        $this->assertSame('3100000.00', $result->amount);
        foreach ([[$input, 'monthlyPrice'], [$result, 'amount']] as [$object, $field]) {
            try {
                $object->$field = '3500000.00';
                $this->fail('DTO harus immutable.');
            } catch (Error $exception) {
                $this->assertStringContainsString('readonly', $exception->getMessage());
            }
        }
    }

    public function test_master_price_is_not_an_input_or_a_fallback_for_the_enrollment_snapshot(): void
    {
        $enrollmentSnapshot = $this->input(['monthlyPrice' => '3100000.00']);
        $masterPrice = '3500000.00';
        $first = (new InvoiceCalculator)->calculate($enrollmentSnapshot);
        $masterPrice = '999999999999.99';
        $second = (new InvoiceCalculator)->calculate($enrollmentSnapshot);
        $this->assertSame('999999999999.99', $masterPrice);
        $this->assertSame('3100000.00', $second->monthlyRate);
        $this->assertEquals($first, $second);
        // Verifikasi mapping database ke DTO tetap di luar unit test pure ini.
    }
}
