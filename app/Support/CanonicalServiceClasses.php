<?php

namespace App\Support;

use App\Models\ServiceClass;
use Illuminate\Validation\ValidationException;

final class CanonicalServiceClasses
{
    public const DEFINITIONS = [
        'vip' => ['room_type' => 'private', 'family_live_cctv' => true, 'instant_verified_emergency' => true,
            'daily_gait_report' => true, 'weekly_gait_report' => true, 'monthly_gait_report' => true, 'verified_incident_summary' => true],
        'class_2' => ['room_type' => 'shared', 'family_live_cctv' => false, 'instant_verified_emergency' => true,
            'daily_gait_report' => false, 'weekly_gait_report' => true, 'monthly_gait_report' => true, 'verified_incident_summary' => true],
        'class_3' => ['room_type' => 'shared', 'family_live_cctv' => false, 'instant_verified_emergency' => true,
            'daily_gait_report' => false, 'weekly_gait_report' => false, 'monthly_gait_report' => true, 'verified_incident_summary' => true],
    ];

    public static function matches(ServiceClass $class): bool
    {
        $definition = self::DEFINITIONS[$class->code] ?? null;
        if ($definition === null) {
            return false;
        }
        foreach ($definition as $field => $value) {
            if ($class->getAttribute($field) !== $value) {
                return false;
            }
        }

        return true;
    }

    public static function ensureMatches(ServiceClass $class): void
    {
        if (! self::matches($class)) {
            throw ValidationException::withMessages(['service_class_id' => 'Konfigurasi kelas tidak sesuai aturan Silansia. Perlu pemeriksaan operator sebelum perubahan dilanjutkan.']);
        }
    }
}
