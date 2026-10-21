<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'room_type', 'monthly_price', 'family_live_cctv', 'instant_verified_emergency', 'daily_gait_report', 'weekly_gait_report', 'monthly_gait_report', 'verified_incident_summary', 'is_active'])]
class ServiceClass extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'family_live_cctv' => 'boolean',
            'instant_verified_emergency' => 'boolean',
            'daily_gait_report' => 'boolean',
            'weekly_gait_report' => 'boolean',
            'monthly_gait_report' => 'boolean',
            'verified_incident_summary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function serviceEnrollments(): HasMany
    {
        return $this->hasMany(ServiceEnrollment::class);
    }
}
