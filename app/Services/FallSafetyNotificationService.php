<?php

namespace App\Services;

use App\Exceptions\NotificationDeliveryException;
use App\Support\StaffNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class FallSafetyNotificationService
{
    public function deliver(string $incidentId): int
    {
        if (! Str::isUuid($incidentId) || DB::transactionLevel() !== 0 || DB::getDriverName() !== 'pgsql') {
            throw new LogicException('Fan-out harus memakai UUID sah dan transaksi PostgreSQL terpisah dari sumber.');
        }
        $cursor = null;
        $inserted = 0;
        try {
            do {
                $batch = DB::transaction(function () use ($incidentId, $cursor) {
                    if (DB::scalar('SHOW transaction_isolation') !== 'read committed') {
                        throw new LogicException('Fan-out memerlukan READ COMMITTED.');
                    }
                    DB::statement("SET LOCAL lock_timeout = '2s'");
                    // Semua user locks mendahului source/FK incident, sesuai urutan layanan existing.
                    $recipients = DB::table('users')->where('is_active', true)->whereIn('role', ['admin', 'perawat'])
                        ->when($cursor, fn ($q) => $q->where('id', '>', $cursor))
                        ->orderBy('id')->limit(100)->lock('for key share')->get(['id']);
                    if ($recipients->isEmpty() || ! StaffNotification::pending()->where('f.id', $incidentId)
                        ->lock('for key share of f')->first(['f.id'])) {
                        return ['count' => 0, 'cursor' => null];
                    }
                    $time = CameraOperatingPeriodService::serverTime()->startOfSecond()->format('Y-m-d H:i:sP');
                    $values = [];
                    foreach ($recipients as $recipient) {
                        array_push($values, (string) Str::uuid7(), StaffNotification::KIND, StaffNotification::MORPH,
                            $recipient->id, $incidentId, json_encode(['schema_version' => 1, 'message' => StaffNotification::MESSAGE], JSON_THROW_ON_ERROR),
                            $time, $time);
                    }
                    $slots = implode(',', array_fill(0, $recipients->count(), '(?,?,?,?,?,?,NULL,?,?)'));
                    $count = DB::affectingStatement("INSERT INTO notifications
                        (id,type,notifiable_type,notifiable_id,fall_incident_id,data,read_at,created_at,updated_at)
                        VALUES $slots ON CONFLICT (notifiable_type,notifiable_id,type,fall_incident_id)
                        WHERE type='silansia.fall_pending.v1' AND fall_incident_id IS NOT NULL DO NOTHING", $values);

                    return ['count' => $count, 'cursor' => $recipients->count() === 100 ? $recipients->last()->id : null];
                });
                $inserted += $batch['count'];
                $cursor = $batch['cursor'];
            } while ($cursor !== null);
        } catch (QueryException) {
            // Tidak meneruskan SQL/bindings atau exception sebelumnya ke log/failed_jobs.
            throw new NotificationDeliveryException;
        }

        return $inserted;
    }

    public function reconcile(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500 || DB::transactionLevel() !== 0) {
            throw new LogicException('Batas rekonsiliasi tidak valid atau transaksi sumber belum selesai.');
        }
        try {
            // Lewati pending yang semua recipient aktifnya sudah memiliki row: hindari starvation.
            $ids = StaffNotification::pending()->whereExists(function ($users) {
                $users->selectRaw('1')->from('users as u')->where('u.is_active', true)->whereIn('u.role', ['admin', 'perawat'])
                    ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('notifications as n')
                        ->whereColumn('n.notifiable_id', 'u.id')->whereColumn('n.fall_incident_id', 'f.id')
                        ->where('n.notifiable_type', StaffNotification::MORPH)->where('n.type', StaffNotification::KIND));
            })->orderBy('f.created_at')->orderBy('f.id')->limit($limit)->pluck('f.id');
        } catch (QueryException) {
            throw new NotificationDeliveryException;
        }
        $result = ['processed' => 0, 'inserted' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $result['processed']++;
            try {
                $result['inserted'] += $this->deliver($id);
            } catch (NotificationDeliveryException) {
                $result['failed']++;
            }
        }

        return $result;
    }
}
