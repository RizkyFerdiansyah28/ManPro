<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'elderly_id', 'service_enrollment_id', 'billing_month', 'amount', 'currency', 'due_date', 'grace_period_ends_on', 'status', 'paid_at'])]
class Invoice extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'billing_month' => 'immutable_date',
            'amount' => 'decimal:2',
            'due_date' => 'immutable_date',
            'grace_period_ends_on' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function elderly(): BelongsTo
    {
        return $this->belongsTo(Elderly::class);
    }

    public function serviceEnrollment(): BelongsTo
    {
        return $this->belongsTo(ServiceEnrollment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
