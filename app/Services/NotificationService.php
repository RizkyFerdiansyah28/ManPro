<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Support\StaffNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationService
{
    public function read(User $actor, ?string $id = null): int
    {
        Gate::forUser($actor)->authorize('viewAny', Notification::class);
        abort_if($id !== null && ! Str::isUuid($id), 404);
        try {
            return DB::transaction(function () use ($actor, $id) {
                DB::statement("SET LOCAL lock_timeout = '2s'");
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('viewAny', Notification::class);
                $query = StaffNotification::inbox($user);
                if ($id !== null) {
                    $record = Notification::whereKey($id)->where('notifiable_type', StaffNotification::MORPH)
                        ->where('notifiable_id', $user->id)->where('type', StaffNotification::KIND)->firstOrFail();
                    Gate::forUser($user)->authorize('read', $record);
                    $query->where('n.id', $id);
                }

                return $query->whereNull('n.read_at')->update(['read_at' => CameraOperatingPeriodService::serverTime()
                    ->startOfSecond()->format('Y-m-d H:i:sP')]);
            });
        } catch (QueryException) {
            throw new HttpException(503, 'Status baca notifikasi belum dapat disimpan.');
        }
    }
}
