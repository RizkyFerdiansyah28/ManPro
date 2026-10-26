<?php

namespace App\Services;

use App\Exceptions\AiGatewayException;
use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\CameraOperatingPeriod;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Support\AiGateway\EventPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiIngestionService
{
    public function ingest(EventPayload $payload, array $source): array
    {
        $v = $payload->values;
        if (! in_array($v['camera_id'], $source['camera_ids'] ?? [], true) || ! in_array($v['event_type'], $source['event_types'] ?? [], true)) {
            throw new AiGatewayException(403, 'source_scope_denied', 'Sumber tidak diizinkan untuk tindakan ini.');
        }
        try {
            $externalId = $payload->externalId($source['id']);
            $hash = $payload->hash($source['id']);
            $existing = AiDetection::where('external_event_id', $externalId)->first();
            if ($existing) {
                return $this->duplicate($existing, $payload, $source, $hash);
            }
            $received = CameraOperatingPeriodService::serverTime();

            return DB::transaction(function () use ($payload, $source, $v, $externalId, $hash, $received) {
                if (DB::connection()->getDriverName() !== 'pgsql' || DB::selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
                    throw AiGatewayException::unavailable();
                }
                $timeout = config('ai_gateway.lock_timeout_ms');
                if (! is_int($timeout) || $timeout < 1 || $timeout > 30000) {
                    throw AiGatewayException::unavailable();
                }
                DB::statement("SET LOCAL lock_timeout = '".$timeout."ms'");
                $observedCamera = Camera::find($v['camera_id']);
                $observedPeriod = CameraOperatingPeriod::whereKey($v['camera_period_id'])->where('camera_id', $v['camera_id'])->first();
                if (! $observedCamera || ! $observedPeriod) {
                    $this->invalidPeriod();
                }
                $roomIds = array_values(array_unique([$observedCamera->room_id, $observedPeriod->room_id]));
                $observedRooms = Room::whereIn('id', $roomIds)->get()->keyBy('id');
                if ($observedRooms->count() !== count($roomIds)) {
                    $this->invalidPeriod();
                }
                $classIds = $observedRooms->pluck('service_class_id')->push($observedPeriod->service_class_id)->unique()->all();
                $classes = ServiceClass::whereIn('id', $classIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $rooms = Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($rooms as $room) {
                    if ($room->service_class_id !== $observedRooms->get($room->id)->service_class_id) {
                        $this->stateChanged();
                    }
                }
                $time = $payload->occurredAt->format('Y-m-d H:i:s.uP');
                $stays = ElderlyStay::where('room_id', $observedPeriod->room_id)->where('started_at', '<=', $time)
                    ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $time))->get();
                Elderly::whereIn('id', $stays->pluck('elderly_id'))->orderBy('id')->lockForUpdate()->get();
                $camera = Camera::whereKey($v['camera_id'])->lockForUpdate()->first();
                $period = CameraOperatingPeriod::whereKey($v['camera_period_id'])->where('camera_id', $v['camera_id'])->lockForUpdate()->first();
                if (! $camera || ! $period || $camera->room_id !== $observedCamera->room_id
                    || $period->room_id !== $observedPeriod->room_id || $period->service_class_id !== $observedPeriod->service_class_id) {
                    $this->stateChanged();
                }
                // Worker lain mungkin sudah menerima event ketika transaksi ini menunggu lock.
                if ($existing = AiDetection::where('external_event_id', $externalId)->first()) {
                    return $this->duplicate($existing, $payload, $source, $hash);
                }
                if ($payload->occurredAt->greaterThan($received)
                    || $payload->occurredAt->lessThan($received->subSeconds((int) config('ai_gateway.max_event_age_seconds', 86400)))
                    || $payload->occurredAt->lessThan($period->opened_at)
                    || ($period->closed_at !== null && ! $payload->occurredAt->lessThan($period->closed_at))) {
                    $this->invalidPeriod();
                }
                if ($period->closed_at === null && (! $camera->is_active || $camera->room_id !== $period->room_id || $camera->source_type !== $period->source_type)) {
                    $this->stateChanged();
                }
                $id = (string) Str::uuid7();
                $metadata = json_encode(['gateway' => ['hash_version' => 1, 'contract_version' => '1',
                    'source_id' => $source['id'], 'producer_event_id' => $v['external_event_id'], 'payload_hash' => $hash, 'key_id' => $source['key_id']]], JSON_THROW_ON_ERROR);
                if (strlen($metadata) > 1024) {
                    throw AiGatewayException::unavailable();
                }
                $receivedValue = $received->format('Y-m-d H:i:s.uP');
                $inserted = DB::selectOne('INSERT INTO ai_detections
                    (id,camera_id,camera_period_id,external_event_id,event_type,occurred_at,received_at,confidence,metadata,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT (external_event_id) DO NOTHING RETURNING id',
                    [$id, $camera->id, $period->id, $externalId, $v['event_type'], $time, $receivedValue, $v['confidence'], $metadata, $receivedValue, $receivedValue]);
                if (! $inserted) {
                    $winner = AiDetection::where('external_event_id', $externalId)->first();
                    if (! $winner) {
                        throw AiGatewayException::unavailable();
                    }

                    return $this->duplicate($winner, $payload, $source, $hash);
                }
                $event = AiDetection::findOrFail($id);
                if ($v['event_type'] === 'fall') {
                    $event->fallIncident()->create([]); // Pending tanpa identitas/verifikasi/notifikasi.
                } else {
                    $elderlyId = null;
                    if ($period->room_type === 'private' && $period->room_capacity === 1
                        && $classes->get($period->service_class_id)?->code === 'vip' && $stays->count() === 1) {
                        $enrollments = ServiceEnrollment::where('elderly_id', $stays->sole()->elderly_id)->where('started_at', '<=', $time)
                            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $time))->get();
                        if ($enrollments->count() === 1 && $enrollments->sole()->service_class_id === $period->service_class_id) {
                            $elderlyId = $stays->sole()->elderly_id;
                        }
                    }
                    $event->gaitObservation()->create(['category' => $v['category'], 'elderly_id' => $elderlyId,
                        'identity_basis' => $elderlyId ? 'vip_room_single_resident' : 'unknown']);
                }

                return ['accepted' => true, 'duplicate' => false, 'detection_id' => $id];
            });
        } catch (QueryException) {
            // Koneksi, deadlock, timeout, dan constraint lain bukan duplicate/validasi palsu.
            throw AiGatewayException::unavailable();
        }
    }

    private function duplicate(AiDetection $event, EventPayload $payload, array $source, string $hash): array
    {
        $provenance = $event->metadata['gateway'] ?? null;
        if (! is_array($provenance) || ($provenance['hash_version'] ?? null) !== 1 || ($provenance['source_id'] ?? null) !== $source['id']
            || ! is_string($provenance['payload_hash'] ?? null) || ! hash_equals($hash, $provenance['payload_hash'])
            || $event->camera_id !== $payload->values['camera_id'] || $event->camera_period_id !== $payload->values['camera_period_id']
            || $event->event_type !== $payload->values['event_type']) {
            throw new AiGatewayException(409, 'event_conflict', 'Identitas event sudah dipakai dengan payload berbeda atau provenance tidak cocok.');
        }
        $fall = $event->fallIncident()->exists();
        $gait = $event->gaitObservation()->exists();
        if (($event->event_type === 'fall' && (! $fall || $gait)) || ($event->event_type === 'gait' && (! $gait || $fall))) {
            throw AiGatewayException::unavailable();
        }

        return ['accepted' => true, 'duplicate' => true, 'detection_id' => $event->id];
    }

    private function invalidPeriod(): never
    {
        throw new AiGatewayException(422, 'invalid_event_context', 'Kamera, periode, atau waktu event tidak valid.');
    }

    private function stateChanged(): never
    {
        throw new AiGatewayException(409, 'camera_state_changed', 'Konfigurasi kamera berubah. Muat konteks capture yang sah sebelum mencoba kembali.');
    }
}
