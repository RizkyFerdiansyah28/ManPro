<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['service_class_id', 'code', 'name', 'capacity', 'is_active'])]
class Room extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function serviceClass(): BelongsTo
    {
        return $this->belongsTo(ServiceClass::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(ElderlyStay::class);
    }

    public function camera(): HasOne
    {
        return $this->hasOne(Camera::class);
    }
}
