<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['camera_id', 'room_id', 'service_class_id', 'room_type', 'room_capacity', 'source_type', 'opened_at', 'closed_at'])]
class CameraOperatingPeriod extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'room_capacity' => 'integer'];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function serviceClass(): BelongsTo
    {
        return $this->belongsTo(ServiceClass::class);
    }

    public function aiDetections(): HasMany
    {
        return $this->hasMany(AiDetection::class, 'camera_period_id');
    }
}
