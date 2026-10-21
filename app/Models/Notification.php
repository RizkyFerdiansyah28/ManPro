<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

#[Hidden(['data'])]
class Notification extends DatabaseNotification
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function fallIncident(): BelongsTo
    {
        return $this->belongsTo(FallIncident::class);
    }
}
