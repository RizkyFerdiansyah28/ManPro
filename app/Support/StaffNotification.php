<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StaffNotification
{
    public const KIND = 'silansia.fall_pending.v1';

    public const MORPH = 'App\\Models\\User';

    public const MESSAGE = 'Dugaan insiden jatuh tercatat.';

    public static function inbox(User $user): Builder
    {
        return DB::table('notifications as n')->where('n.notifiable_type', self::MORPH)
            ->where('n.notifiable_id', $user->id)->where('n.type', self::KIND);
    }

    public static function pending(): Builder
    {
        return DB::table('fall_incidents as f')->join('ai_detections as d', 'd.id', '=', 'f.ai_detection_id')
            ->where('d.event_type', 'fall')->where('f.status', 'pending')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('gait_observations as g')->whereColumn('g.ai_detection_id', 'd.id'));
    }
}
