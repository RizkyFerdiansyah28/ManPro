<?php

declare(strict_types=1);

namespace App\Services\Billing;

use InvalidArgumentException;

final readonly class InvoiceEligibilityInput
{
    /**
     * NULL lists = bukti belum dibaca; [] = caller menyatakan hasil query lengkap kosong.
     * Tidak ada actor/authorization/guard-valid boolean dari browser.
     *
     * @param  ?list<EnrollmentSnapshot>  $enrollments
     * @param  ?list<OwnershipPeriodSnapshot>  $ownershipPeriods
     * @param  ?list<OwnershipActionSnapshot>  $ownershipActions
     */
    public function __construct(
        public string $elderlyId,
        public string $currentFamilyId,
        public bool $residentActive,
        public string $billingMonth,
        public string $asOf,
        public string $policyVersion,
        public ?string $existingInvoiceId,
        public ?array $enrollments,
        public ?array $ownershipPeriods,
        public ?array $ownershipActions,
    ) {
        foreach ([[$enrollments, EnrollmentSnapshot::class], [$ownershipPeriods, OwnershipPeriodSnapshot::class],
            [$ownershipActions, OwnershipActionSnapshot::class]] as [$items, $type]) {
            if ($items !== null && ! array_is_list($items)) {
                throw new InvalidArgumentException('Snapshot harus berupa list DTO immutable yang sesuai.');
            }
            foreach ($items ?? [] as $item) {
                if (! $item instanceof $type) {
                    throw new InvalidArgumentException('Snapshot harus berupa list DTO immutable yang sesuai.');
                }
            }
        }
    }
}
