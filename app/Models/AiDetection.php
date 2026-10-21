<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['camera_id', 'external_event_id', 'event_type', 'occurred_at', 'received_at', 'confidence', 'metadata'])]
#[Hidden(['metadata'])]
class AiDetection extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'confidence' => 'decimal:5',
            'metadata' => 'array',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function gaitObservation(): HasOne
    {
        return $this->hasOne(GaitObservation::class);
    }

    public function fallIncident(): HasOne
    {
        return $this->hasOne(FallIncident::class);
    }
}
