<?php

namespace App\Services;

use App\Exceptions\IncidentConflictException;
use App\Models\Elderly;
use App\Models\FallIncident;
use App\Models\FallIncidentAction;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use App\Support\IncidentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IncidentService
{
    public function __construct(private IncidentReadService $read) {}

    /** @return array{incident: FallIncident, duplicate: bool} */
    public function act(User $actor, string $id, string $action, array $input): array
    {
        Gate::forUser($actor)->authorize('handle', FallIncident::class);
        abort_unless(Str::isUuid($id), 404);
        $intent = $this->intent($id, $action, $input);
        $fingerprint = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $id, $action, $input, $intent, $fingerprint) {
                if (DB::getDriverName() !== 'pgsql'
                    || DB::selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
                    throw new LogicException('Penanganan insiden memerlukan PostgreSQL READ COMMITTED.');
                }
                DB::statement("SET LOCAL lock_timeout = '5s'");
                $caregiver = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($caregiver)->authorize('handle', FallIncident::class);
                // Actor lock juga menyerialkan request_id yang sama pada insiden berbeda.
                $previous = FallIncidentAction::where('actor_id', $caregiver->id)->where('request_id', $input['request_id'])->first();
                if ($previous) {
                    if (! hash_equals($previous->request_fingerprint, $fingerprint)) {
                        throw new IncidentConflictException;
                    }
                    $incident = FallIncident::whereKey($id)->lockForUpdate()->firstOrFail();

                    return ['incident' => $incident, 'duplicate' => true];
                }

                $observed = $this->read->context($id);
                abort_unless($observed, 404);
                if ($action === 'verify' && $intent['identity_mode'] === 'known') {
                    if (! $observed->valid_period) {
                        $this->invalidIdentity();
                    }
                    ServiceClass::whereIn('id', array_unique([$observed->service_class_id, $observed->current_class_id]))
                        ->orderBy('id')->lockForUpdate()->get();
                    $room = Room::whereKey($observed->room_id)->lockForUpdate()->firstOrFail();
                    if ($room->service_class_id !== $observed->current_class_id) {
                        throw new IncidentConflictException;
                    }
                    // Tidak memeriksa active/benefit/billing; ini bukti historis, bukan hak keluarga.
                    if (! Elderly::whereKey($intent['elderly_id'])->lockForUpdate()->first()) {
                        $this->invalidIdentity();
                    }
                }
                $incident = FallIncident::whereKey($id)->lockForUpdate()->firstOrFail();
                Gate::forUser($caregiver)->authorize('view', $incident);
                IncidentVersion::ensureMatches($incident, $input['expected_version']);
                $actual = $this->read->context($id);
                if (! $actual || $incident->ai_detection_id !== $observed->detection_id
                    || serialize($actual) !== serialize($observed)) {
                    throw new IncidentConflictException;
                }
                $verified = $incident->verified_at !== null && $incident->verified_by !== null;
                $valid = match ($action) {
                    'acknowledge' => $incident->status === 'pending',
                    'verify', 'false_alarm' => $incident->status === 'acknowledged' && ! $verified,
                    'resolve' => $incident->status === 'acknowledged' && $verified,
                    default => false,
                };
                if (! $valid) {
                    throw new IncidentConflictException;
                }
                if ($action === 'verify' && $intent['identity_mode'] === 'known'
                    && ! $this->read->candidates($id)->where('e.id', $intent['elderly_id'])->exists()) {
                    $this->invalidIdentity();
                }
                $time = CameraOperatingPeriodService::serverTime();
                $last = $incident->actions()->reorder()->orderByDesc('sequence')->first();
                foreach ([$incident->acknowledged_at, $incident->verified_at, $incident->resolved_at, $last?->acted_at] as $prior) {
                    if ($prior && $time->lessThan($prior)) {
                        throw new IncidentConflictException;
                    }
                }
                $from = $incident->status;
                if ($action === 'acknowledge') {
                    $incident->status = 'acknowledged';
                    $incident->responding_caregiver_id = $caregiver->id;
                    $incident->acknowledged_at = $time;
                } elseif ($action === 'verify') {
                    $incident->verified_by = $caregiver->id;
                    $incident->verified_at = $time;
                    $incident->elderly_id = $intent['elderly_id'];
                } else {
                    $incident->status = $action === 'resolve' ? 'resolved' : 'false_alarm';
                    $incident->resolved_at = $time;
                }
                $incident->updated_at = $time;
                $incident->save();
                $incident->actions()->create(['actor_id' => $caregiver->id, 'request_id' => $input['request_id'],
                    'sequence' => ($last?->sequence ?? 0) + 1, 'action' => $action, 'from_status' => $from,
                    'to_status' => $incident->status, 'elderly_id' => $action === 'verify' ? $intent['elderly_id'] : null,
                    'notes' => $intent['notes'], 'request_fingerprint' => $fingerprint, 'acted_at' => $time]);

                return ['incident' => $incident->fresh(), 'duplicate' => false];
            });
        } catch (QueryException $exception) {
            // Jangan meneruskan SQL/bindings yang memuat catatan privat ke exception reporter.
            throw new HttpException(503, 'Penanganan insiden belum dapat disimpan. Muat ulang detail sebelum mencoba kembali.');
        }
    }

    private function intent(string $id, string $action, array $input): array
    {
        $notes = isset($input['notes']) ? trim($input['notes']) : null;
        $notes = $notes === '' ? null : $notes;
        $mode = $action === 'verify' ? ($input['identity_mode'] ?? null) : null;
        $elderly = $mode === 'known' ? ($input['elderly_id'] ?? null) : null;
        if (! in_array($action, ['acknowledge', 'verify', 'resolve', 'false_alarm'], true)
            || ! Str::isUuid($input['request_id'] ?? '')
            || ! is_string($input['expected_version'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $input['expected_version'])
            || (! ($input['confirm'] ?? false))
            || ($action !== 'acknowledge' && $notes === null) || ($notes !== null && mb_strlen($notes) > 2000)
            || ($action === 'verify' && (! in_array($mode, ['known', 'unknown'], true) || ($mode === 'known' && ! Str::isUuid($elderly ?? ''))))) {
            throw ValidationException::withMessages(['incident' => 'Data tindakan insiden tidak valid.']);
        }

        return ['incident_id' => $id, 'action' => $action, 'expected_version' => $input['expected_version'],
            'identity_mode' => $mode, 'elderly_id' => $elderly, 'notes' => $notes];
    }

    private function invalidIdentity(): never
    {
        throw ValidationException::withMessages(['elderly_id' => 'Lansia tidak termasuk kandidat historis yang sah. Muat ulang pilihan atau gunakan identitas belum diketahui.']);
    }
}
