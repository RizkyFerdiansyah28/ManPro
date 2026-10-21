<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ai_detection_id', 'elderly_id', 'status', 'responding_caregiver_id', 'acknowledged_at', 'verified_by', 'verified_at', 'resolved_at', 'notes'])]
#[Hidden(['notes'])]
class FallIncident extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function aiDetection(): BelongsTo
    {
        return $this->belongsTo(AiDetection::class);
    }

    public function elderly(): BelongsTo
    {
        return $this->belongsTo(Elderly::class);
    }

    public function respondingCaregiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responding_caregiver_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
