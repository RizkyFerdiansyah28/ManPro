<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['elderly_id', 'service_class_id', 'monthly_price', 'started_at', 'ended_at'])]
class ServiceEnrollment extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    public function elderly(): BelongsTo
    {
        return $this->belongsTo(Elderly::class);
    }

    public function serviceClass(): BelongsTo
    {
        return $this->belongsTo(ServiceClass::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
