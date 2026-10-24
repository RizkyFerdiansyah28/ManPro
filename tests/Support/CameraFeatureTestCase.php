<?php

namespace Tests\Support;

use App\Models\Camera;
use App\Models\Room;
use App\Support\CameraVersion;

abstract class CameraFeatureTestCase extends FacilityFeatureTestCase
{
    protected const SOURCE = 'rtsp://fixture-user:fixture-secret@camera.example.test:554/live?token=fixture-token';

    protected function camera(?Room $room = null, array $changes = []): Camera
    {
        return Camera::create(['room_id' => ($room ?? $this->room())->id, 'name' => 'Kamera Uji',
            'source_type' => 'webcam', 'is_active' => false, ...$changes])->fresh();
    }

    protected function cameraPayload(Room $room, array $changes = []): array
    {
        return ['room_id' => $room->id, 'name' => 'Kamera Baru', 'source_type' => 'webcam', 'confirm_create' => true, ...$changes];
    }

    protected function cameraUpdate(Camera $camera, array $changes = []): array
    {
        $camera = $camera->fresh();

        return ['room_id' => $camera->room_id, 'current_room_id' => $camera->room_id, 'name' => $camera->name,
            'source_type' => $camera->source_type, 'source_action' => 'keep', 'version' => CameraVersion::of($camera),
            'confirm_reassignment' => false, ...$changes];
    }

    protected function statusPayload(Camera $camera): array
    {
        return ['version' => CameraVersion::of($camera->fresh()), 'confirm_status_change' => true];
    }
}
