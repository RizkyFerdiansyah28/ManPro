<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\EnrollmentSnapshot;
use App\Services\Billing\InvoiceCanonical;
use App\Services\Billing\InvoiceDueDates;
use App\Services\Billing\InvoiceIssuanceFailure;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\InvoiceIssueConfirmation;
use App\Services\Billing\InvoiceSourceSnapshot;
use App\Services\Billing\OwnershipActionSnapshot;
use App\Services\Billing\OwnershipPeriodSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceIssuanceContractTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';

    private const ELDERLY = '22222222-2222-4222-8222-222222222222';

    private const ENROLLMENT = '33333333-3333-4333-8333-333333333333';

    private const FAMILY = '44444444-4444-4444-8444-444444444444';

    private const PERIOD = '55555555-5555-4555-8555-555555555555';

    private function facts(): array
    {
        return ['elderly_id' => self::ELDERLY, 'billing_month' => '2026-10-01', 'service_enrollment_id' => self::ENROLLMENT,
            'financial_owner_id' => self::FAMILY, 'ownership_period_id' => self::PERIOD, 'issued_by' => self::ADMIN,
            'issued_at' => '2026-11-01T00:00:00.123456Z', 'policy_version' => 'calendar-wib-daily-half-up-v1',
            'monthly_price' => '3100.00', 'effective_started_at' => '2026-09-30T17:00:00.000000Z',
            'effective_ended_at' => '2026-10-31T17:00:00.000000Z', 'chargeable_days' => 31, 'days_in_month' => 31,
            'amount' => '3100.00', 'currency' => 'IDR', 'due_date' => '2026-11-08', 'grace_period_ends_on' => '2026-11-11'];
    }

    public function test_fixed_hash_vectors_match_independently_encoded_utf8_bytes(): void
    {
        $canonical = new InvoiceCanonical;
        $command = new InvoiceIssueCommand(strtoupper(self::ADMIN), self::ELDERLY, '2026-10-01', self::ENROLLMENT,
            new InvoiceIssueConfirmation(str_repeat('a', 64)));
        $this->assertSame('b99c8cc7239d88ec5591e86ed96420778908ae23cee8d02a998cde6eebb99709', $canonical->intent($command));
        $this->assertSame('686abfa631499066a91d0f612b50fa6e757e05cf104911da3e0383d3375ef301', $canonical->content($this->facts()));
        $anotherKey = new InvoiceIssueCommand($command->actorId, $command->elderlyId, $command->billingMonth, self::PERIOD, $command->confirmation);
        $this->assertSame($canonical->intent($command), $canonical->intent($anotherKey));
        $this->assertSame($canonical->content($this->facts()), $canonical->content(array_reverse($this->facts(), true)));
        $this->assertSame($canonical->content($this->facts()), $canonical->content([...$this->facts(),
            'issued_at' => '2026-11-01T07:00:00.123456+07:00', 'content_hash' => 'ignored self hash']));
        $this->assertTrue((new \ReflectionClass($command))->isReadOnly());
        $this->assertTrue((new \ReflectionClass($command->confirmation))->isReadOnly());
        $this->assertSame(['actorId', 'elderlyId', 'requestId', 'billingMonth', 'confirmation'],
            array_map(fn ($p) => $p->name, (new \ReflectionClass($command))->getProperties()));
    }

    public static function materialFacts(): array
    {
        return [['monthly_price', '3100.01'], ['chargeable_days', 30], ['days_in_month', 30], ['amount', '3100.01'],
            ['policy_version', 'different-policy-v2'], ['financial_owner_id', self::ADMIN], ['ownership_period_id', self::ADMIN],
            ['effective_started_at', '2026-09-30T17:00:00.000001Z']];
    }

    #[DataProvider('materialFacts')]
    public function test_content_hash_binds_each_material_fact(string $field, mixed $value): void
    {
        $canonical = new InvoiceCanonical;
        $this->assertNotSame($canonical->content($this->facts()), $canonical->content([...$this->facts(), $field => $value]));
    }

    public function test_source_fingerprint_ignores_order_timezone_spelling_and_nonfinancial_resident_status(): void
    {
        $enrollment = new EnrollmentSnapshot(self::ENROLLMENT, self::ELDERLY, self::ADMIN, '2026-10-01T00:00:00+07', null, '3100.00');
        $period = new OwnershipPeriodSnapshot(self::PERIOD, self::ELDERLY, self::FAMILY, null,
            '2026-09-01T00:00:00.123456+07', null, self::ADMIN, '2026-09-01T00:00:00.123456+07', 'cutover_capture');
        $action = new OwnershipActionSnapshot(self::PERIOD, self::ADMIN, 'ownership_captured', $period->recordedAt);
        $source = new InvoiceSourceSnapshot(self::ELDERLY, self::FAMILY, true, [$enrollment], [$period], [$action], null);
        $same = new InvoiceSourceSnapshot(self::ELDERLY, self::FAMILY, false, [$enrollment], [$period], [$action], self::ADMIN);
        $canonical = new InvoiceCanonical;
        $hash = $canonical->source($source, '2026-10-01');
        $this->assertSame($hash, $canonical->source($same, '2026-10-01'));
        $other = new EnrollmentSnapshot(self::ADMIN, self::ELDERLY, self::ADMIN, '2026-09-01T00:00:00Z', '2026-09-02T00:00:00Z', '5.00');
        $left = new InvoiceSourceSnapshot(self::ELDERLY, self::FAMILY, true, [$enrollment, $other], [$period], [$action], null);
        $right = new InvoiceSourceSnapshot(self::ELDERLY, self::FAMILY, true, [$other, $enrollment], [$period], [$action], null);
        $this->assertSame($canonical->source($left, '2026-10-01'), $canonical->source($right, '2026-10-01'));
        $this->assertNotSame($hash, $canonical->source($source, '2026-09-01'));
        $utc = new EnrollmentSnapshot(self::ENROLLMENT, self::ELDERLY, self::ADMIN, '2026-09-30T17:00:00.000000Z', null, '3100.00');
        $this->assertSame($hash, $canonical->source(new InvoiceSourceSnapshot(self::ELDERLY, self::FAMILY, true, [$utc], [$period], [$action], null), '2026-10-01'));
    }

    public static function dueDates(): array
    {
        return [['2026-12-28T16:59:59.999999Z', '2027-01-04', '2027-01-07'],
            ['2026-12-28T17:00:00.000000Z', '2027-01-05', '2027-01-08'],
            ['2028-02-28T17:00:00.123456Z', '2028-03-07', '2028-03-10'],
            ['2026-11-30T16:59:59.999999Z', '2026-12-07', '2026-12-10']];
    }

    #[DataProvider('dueDates')]
    public function test_due_grace_calendar_wib_boundaries(string $at, string $due, string $grace): void
    {
        $this->assertSame([$due, $grace], InvoiceDueDates::fromIssuedAt($at));
    }

    public static function invalidCommands(): array
    {
        return [['not-uuid', '2026-10-01'], [self::ADMIN, '2026-10-02'], [self::ADMIN, '2026-13-01']];
    }

    #[DataProvider('invalidCommands')]
    public function test_invalid_command_is_explicit(string $actor, string $month): void
    {
        try {
            new InvoiceIssueCommand($actor, self::ELDERLY, $month, self::ENROLLMENT, new InvoiceIssueConfirmation(str_repeat('a', 64)));
            $this->fail('Command harus ditolak.');
        } catch (InvoiceIssuanceFailure $failure) {
            $this->assertSame('invalid_command', $failure->kind);
        }
    }
}
