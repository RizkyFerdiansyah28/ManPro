<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['family_id', 'name', 'birth_date', 'is_active'])]
class Elderly extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $table = 'elderlies';

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    protected function age(): Attribute
    {
        return Attribute::make(get: fn (): int => (int) $this->birth_date->diffInYears(today()));
    }

    #[Scope]
    protected function forFamily(Builder $query, User $family): void
    {
        $query->where('family_id', $family->id);
        if (! $family->is_active || $family->role !== 'keluarga') {
            $query->whereRaw('1 = 0');
        }
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(User::class, 'family_id');
    }

    public function stays(): HasMany
    {
        return $this->hasMany(ElderlyStay::class);
    }

    public function activeStay(): HasOne
    {
        return $this->hasOne(ElderlyStay::class)->whereNull('ended_at');
    }

    public function serviceEnrollments(): HasMany
    {
        return $this->hasMany(ServiceEnrollment::class);
    }

    // Nama relasi untuk scoped binding /enrollments/{enrollment}; schema tetap sama.
    public function enrollments(): HasMany
    {
        return $this->serviceEnrollments();
    }

    public function activeServiceEnrollment(): HasOne
    {
        return $this->hasOne(ServiceEnrollment::class)->whereNull('ended_at');
    }

    public function gaitObservations(): HasMany
    {
        return $this->hasMany(GaitObservation::class);
    }

    public function fallIncidents(): HasMany
    {
        return $this->hasMany(FallIncident::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
