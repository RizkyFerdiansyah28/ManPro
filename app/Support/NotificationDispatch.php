<?php

namespace App\Support;

use App\Jobs\ReconcileFallNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotificationDispatch
{
    public static function afterCommit(string $incidentId): void
    {
        try {
            DB::afterCommit(function () use ($incidentId) {
                try {
                    Bus::dispatch(new ReconcileFallNotifications($incidentId));
                } catch (Throwable) {
                    self::warning();
                }
            });
        } catch (Throwable) {
            self::warning();
        }
    }

    private static function warning(): void
    {
        try {
            Log::warning('notifications_dispatch_failed');
        } catch (Throwable) {
            // Kegagalan logger juga tidak boleh mengubah hasil commit keselamatan.
        }
    }
}
