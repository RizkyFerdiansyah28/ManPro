<?php

namespace Tests\Feature\Cameras;

use App\Models\Camera;
use App\Services\CameraService;
use App\Support\CameraVersion;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CameraFeatureTestCase;

class CameraManagementTest extends CameraFeatureTestCase
{
    public function test_admin_lists_creates_and_views_all_four_pages_without_streaming_props(): void
    {
        $this->get('/admin/cameras')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Cameras/Index')->has('cameras.data', 0));
        $this->get('/admin/cameras/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Cameras/Create'));
        $room = $this->room();
        $before = $room->getAttributes();
        $this->post('/admin/cameras', $this->cameraPayload($room))->assertSessionHasNoErrors()->assertRedirect();
        $camera = Camera::sole();
        $this->assertTrue(Str::isUuid($camera->id));
        $this->assertFalse($camera->is_active);
        $this->assertNull($camera->source_reference);
        $this->assertSame($before, $room->fresh()->getAttributes());
        $this->get("/admin/cameras/{$camera->id}")->assertInertia(fn (Assert $page) => $page->component('Admin/Cameras/Show')
            ->where('camera.room.id', $room->id)->where('camera.has_source_reference', false)->where('camera.detections_count', 0)
            ->missing('camera.source_reference')->missing('camera.stream_url')->missing('camera.online'));
        $this->get("/admin/cameras/{$camera->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/Cameras/Edit'));
        $this->delete("/admin/cameras/{$camera->id}")->assertStatus(405);
    }

    public static function classCodes(): array
    {
        return [['vip'], ['class_2'], ['class_3']];
    }

    #[DataProvider('classCodes')]
    public function test_all_classes_can_register_and_activate_without_family_cctv_or_billing_gate(string $code): void
    {
        $class = match ($code) {
            'vip' => $this->vip, 'class_2' => $this->shared, 'class_3' => $this->basic
        };
        $before = $class->getAttributes();
        $room = $this->room($class);
        $this->post('/admin/cameras', $this->cameraPayload($room))->assertSessionHasNoErrors();
        $camera = Camera::sole();
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertTrue($camera->fresh()->is_active);
        $this->assertSame($before, $class->fresh()->getAttributes());
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_invalid_rooms_inactive_rooms_and_duplicates_are_rejected(): void
    {
        $room = $this->room();
        foreach (['invalid', (string) Str::uuid()] as $id) {
            $this->post('/admin/cameras', $this->cameraPayload($room, ['room_id' => $id]))->assertSessionHasErrors('room_id');
        }
        $room->update(['is_active' => false]);
        $this->post('/admin/cameras', $this->cameraPayload($room))->assertSessionHasErrors('room_id');
        $this->assertDatabaseCount('cameras', 0);
        $room->update(['is_active' => true]);
        $camera = $this->camera($room);
        $before = $camera->getAttributes();
        $this->post('/admin/cameras', $this->cameraPayload($room))->assertSessionHasErrors('room_id');
        try {
            app(CameraService::class)->create($this->admin, $this->cameraPayload($room));
            $this->fail('Kamera kedua harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('room_id', $exception->errors());
        }
        $this->assertSame($before, $camera->fresh()->getAttributes());
        $this->assertDatabaseCount('cameras', 1);
        $second = $this->room($this->shared, ['code' => 'SECOND']);
        $this->post('/admin/cameras', $this->cameraPayload($second, ['name' => $camera->name]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cameras', 2); // Schema tidak mempunyai constraint nama kamera unik.
    }

    public function test_metadata_edit_preserves_ciphertext_status_and_uuid_and_rejects_same_second_stale_form(): void
    {
        $this->freezeSecond();
        $camera = $this->camera(changes: ['source_type' => 'rtsp', 'source_reference' => self::SOURCE, 'is_active' => true]);
        $before = $camera->getAttributes();
        $payload = $this->cameraUpdate($camera, ['name' => 'Nama baru']);
        $this->patch("/admin/cameras/{$camera->id}", $payload)->assertSessionHasNoErrors();
        $fresh = $camera->fresh();
        $this->assertSame('Nama baru', $fresh->name);
        foreach (['id', 'room_id', 'source_type', 'source_reference', 'is_active', 'created_at', 'updated_at'] as $field) {
            $this->assertSame($before[$field], $fresh->getRawOriginal($field));
        }
        $this->assertNotSame($payload['version'], CameraVersion::of($fresh));
        $this->patch("/admin/cameras/{$camera->id}", [...$payload, 'name' => 'Stale'])->assertSessionHasErrors('version');
        $this->assertSame('Nama baru', $camera->fresh()->name);
    }

    public function test_status_transitions_are_confirmed_idempotent_and_do_not_detach_room(): void
    {
        $camera = $this->camera();
        $root = "/admin/cameras/{$camera->id}";
        $this->post("{$root}/activate", ['version' => CameraVersion::of($camera)])->assertSessionHasErrors('confirm_status_change');
        $this->post("{$root}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertTrue($camera->fresh()->is_active);
        $active = $camera->fresh()->getAttributes();
        $this->post("{$root}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertSame($active, $camera->fresh()->getAttributes());
        $this->post("{$root}/deactivate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $inactive = $camera->fresh()->getAttributes();
        $this->post("{$root}/deactivate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertSame($inactive, $camera->fresh()->getAttributes());
        $this->assertSame($camera->room_id, $camera->fresh()->room_id);
    }

    public function test_inactive_room_preserves_existing_association_and_edits_but_blocks_activation(): void
    {
        $room = $this->room(changes: ['is_active' => false]);
        $camera = $this->camera($room);
        $roomBefore = $room->getAttributes();
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['name' => 'Nama aman']))->assertSessionHasNoErrors();
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasErrors('room_id');
        $this->post("/admin/cameras/{$camera->id}/deactivate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertSame($roomBefore, $room->fresh()->getAttributes());
        $this->assertFalse($camera->fresh()->is_active);
        $this->assertSame($room->id, $camera->fresh()->room_id);
    }

    public static function reassignmentFailures(): array
    {
        return [['active'], ['history'], ['occupied'], ['inactive_destination'], ['inactive_class']];
    }

    #[DataProvider('reassignmentFailures')]
    public function test_each_reassignment_restriction_is_rechecked(string $case): void
    {
        $camera = $this->camera();
        $destination = $this->room($this->basic, ['code' => 'DEST']);
        if ($case === 'active') {
            $camera->update(['is_active' => true]);
        }
        if ($case === 'history') {
            $camera->aiDetections()->create(['external_event_id' => 'fixture-event', 'event_type' => 'fall', 'occurred_at' => now(), 'received_at' => now()]);
        }
        if ($case === 'occupied') {
            $this->camera($destination);
        }
        if ($case === 'inactive_destination') {
            $destination->update(['is_active' => false]);
        }
        if ($case === 'inactive_class') {
            $this->basic->update(['is_active' => false]);
        }
        $before = $this->historySnapshot();
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['room_id' => $destination->id, 'confirm_reassignment' => true]))
            ->assertSessionHasErrors('room_id')->assertSessionDoesntHaveErrors(['version', 'confirm_reassignment']);
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_reassignment_requires_confirmation_and_preserves_operational_history(): void
    {
        $room = $this->room();
        $camera = $this->camera($room);
        $this->stay($room);
        $this->enrollment($this->shared);
        $destination = $this->room($this->basic, ['code' => 'DEST']);
        $before = $this->historySnapshot();
        unset($before['cameras']);
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['room_id' => $destination->id]))->assertSessionHasErrors('confirm_reassignment');
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['room_id' => $destination->id, 'confirm_reassignment' => true]))->assertSessionHasNoErrors();
        $this->assertSame($destination->id, $camera->fresh()->room_id);
        $this->assertFalse($camera->fresh()->is_active);
        $after = $this->historySnapshot();
        unset($after['cameras']);
        $this->assertSame($before, $after);
        $this->assertNull($room->camera()->first());
    }

    public function test_camera_even_inactive_keeps_room_and_class_guards_effective(): void
    {
        $room = $this->room();
        $camera = $this->camera($room);
        $this->post("/admin/rooms/{$room->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        // Representasi data legacy agar guard kamera kelas diuji tanpa penghalang kamar aktif lain.
        $room->update(['is_active' => false]);
        $before = $this->historySnapshot();
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->assertSame($before, $this->historySnapshot());
        $this->assertFalse($camera->fresh()->is_active);
    }

    public function test_search_pagination_and_room_selector_are_scoped_and_do_not_search_private_sources(): void
    {
        $targetRoom = $this->room(changes: ['code' => 'TARGET_01']);
        $target = $this->camera($targetRoom, ['name' => 'Kamera Khusus', 'source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        for ($i = 0; $i < 16; $i++) {
            $this->camera($this->room($this->shared, ['code' => 'ROOM-'.$i]), ['name' => 'Kamera '.$i]);
        }
        $this->get('/admin/cameras')->assertInertia(fn (Assert $page) => $page->has('cameras.data', 15)->where('cameras.total', 17));
        $this->get('/admin/cameras?page=2')->assertInertia(fn (Assert $page) => $page->has('cameras.data', 2));
        $this->get('/admin/cameras?search=khusus&status=inactive')->assertInertia(fn (Assert $page) => $page->has('cameras.data', 1)->where('cameras.data.0.id', $target->id));
        $this->get('/admin/cameras?search=target_')->assertInertia(fn (Assert $page) => $page->has('cameras.data', 1));
        $this->get('/admin/cameras?search=fixture-secret')->assertInertia(fn (Assert $page) => $page->has('cameras.data', 0));
        $this->get('/admin/cameras?search=%25')->assertInertia(fn (Assert $page) => $page->has('cameras.data', 0));
        $this->get('/admin/cameras?room_id='.$targetRoom->id)->assertInertia(fn (Assert $page) => $page->has('cameras.data', 1));
        $free = $this->room($this->basic, ['code' => 'FREE']);
        $this->room($this->basic, ['code' => 'INACTIVE', 'is_active' => false]);
        $this->getJson('/admin/camera-room-options')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $free->id)->assertJsonMissingPath('data.0.camera');
        $this->getJson('/admin/camera-room-options?search=free')->assertJsonCount(1, 'data');
        $this->getJson('/admin/camera-room-options?search=%25')->assertJsonCount(0, 'data');
    }

    public function test_protected_fields_and_invalid_source_types_are_rejected(): void
    {
        $room = $this->room();
        $camera = $this->camera($room);
        foreach (['id' => (string) Str::uuid(), 'is_active' => true, 'created_at' => now()->toIso8601String(), 'service_class_id' => $this->vip->id, 'ai_detections' => []] as $field => $value) {
            $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, [$field => $value]))->assertSessionHasErrors($field);
            $this->post("/admin/cameras/{$camera->id}/activate", [...$this->statusPayload($camera), $field => $value])->assertSessionHasErrors($field);
        }
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_type' => 'hls']))->assertSessionHasErrors('source_type');
        $this->assertFalse($camera->fresh()->is_active);
    }

    public function test_camera_changes_preserve_nonempty_stay_enrollment_invoice_and_payment_history(): void
    {
        $room = $this->room();
        $camera = $this->camera($room);
        $this->stay($room);
        $enrollment = $this->enrollment($this->shared);
        $invoice = $enrollment->invoices()->create(['elderly_id' => $enrollment->elderly_id, 'number' => 'CAMERA-HISTORY',
            'billing_month' => '2026-01-01', 'amount' => '123.45', 'due_date' => '2026-02-01', 'grace_period_ends_on' => '2026-02-04']);
        $invoice->payments()->create(['submitted_by' => $enrollment->elderly->family_id, 'amount' => '123.45', 'proof_path' => 'private/fixture.png']);
        $before = $this->historySnapshot();
        unset($before['cameras']);
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['name' => 'Kamera diperbarui']))->assertSessionHasNoErrors();
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->post("/admin/cameras/{$camera->id}/deactivate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $after = $this->historySnapshot();
        unset($after['cameras']);
        $this->assertSame($before, $after);
    }
}
