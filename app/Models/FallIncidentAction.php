<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['fall_incident_id', 'actor_id', 'request_id', 'sequence', 'action', 'from_status', 'to_status', 'elderly_id', 'notes', 'request_fingerprint', 'acted_at'])]
#[Hidden(['notes', 'request_id', 'request_fingerprint'])]
class FallIncidentAction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['acted_at' => 'immutable_datetime', 'sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Jurnal insiden tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Jurnal insiden tidak dapat dihapus.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(FallIncident::class, 'fall_incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
