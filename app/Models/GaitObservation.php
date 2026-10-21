<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_detection_id', 'elderly_id', 'category', 'identity_basis'])]
class GaitObservation extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function aiDetection(): BelongsTo
    {
        return $this->belongsTo(AiDetection::class);
    }

    public function elderly(): BelongsTo
    {
        return $this->belongsTo(Elderly::class);
    }
}
