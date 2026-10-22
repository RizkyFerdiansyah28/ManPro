<?php

namespace Tests\Feature\Facilities;

use App\Models\AiDetection;
use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FacilityFeatureTestCase;

class RoomManagementTest extends FacilityFeatureTestCase
{
    public static function validRooms(): array
    {
        return [['vip', 1], ['shared', 1], ['shared', 8], ['basic', 12], ['basic', 2147483647]];
    }

    #[DataProvider('validRooms')]
    public function test_admin_creates_valid_rooms_with_uuid_and_configured_capacity(string $class, int $capacity): void
    {
        $this->get('/admin/rooms/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Rooms/Create')->has('classes', 3));
        $this->post('/admin/rooms', $this->roomPayload($this->$class, ['code' => '  NEW-01  ', 'name' => '  Nama Kamar  ', 'capacity' => $capacity]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $room = Room::sole();
        $this->assertTrue(Str::isUuid($room->id));
        $this->assertSame('NEW-01', $room->code);
        $this->assertSame('Nama Kamar', $room->name);
        $this->assertSame($capacity, $room->capacity);
        $this->assertSame($this->$class->id, $room->service_class_id);
        $this->assertTrue($room->is_active);
        $this->assertDatabaseCount('elderly_stays', 0);
        $this->assertDatabaseCount('service_enrollments', 0);
    }

    public static function invalidCapacities(): array
    {
        return [[0], [-1], [1.5], ['two'], [2147483648], [null]];
    }

    #[DataProvider('invalidCapacities')]
    public function test_invalid_capacity_is_the_only_rejected_field(mixed $capacity): void
    {
        $this->post('/admin/rooms', $this->roomPayload(changes: ['capacity' => $capacity]))
            ->assertSessionHasErrors(['capacity'])->assertSessionDoesntHaveErrors(['code', 'name', 'service_class_id']);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_vip_capacity_must_remain_exactly_one_on_create_and_update(): void
    {
        $this->post('/admin/rooms', $this->roomPayload($this->vip, ['capacity' => 2]))->assertSessionHasErrors('capacity');
        $room = $this->room($this->vip);
        $before = $room->getAttributes();
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['capacity' => 2]))->assertSessionHasErrors('capacity');
        $this->assertSame($before, $room->fresh()->getAttributes());
    }

    public function test_unknown_inactive_or_malformed_class_is_rejected(): void
    {
        foreach (['not-uuid', (string) Str::uuid()] as $id) {
            $this->post('/admin/rooms', $this->roomPayload(changes: ['service_class_id' => $id]))->assertSessionHasErrors('service_class_id');
        }
        $this->shared->update(['is_active' => false]);
        $this->get('/admin/rooms/create')->assertInertia(fn (Assert $page) => $page->has('classes', 2));
        $this->post('/admin/rooms', $this->roomPayload())->assertSessionHasErrors('service_class_id');
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_duplicate_codes_fail_http_and_service_constraint_without_partial_write(): void
    {
        $room = $this->room();
        $other = $this->room($this->shared, ['code' => 'OTHER']);
        $before = $other->getAttributes();
        $this->post('/admin/rooms', $this->roomPayload())->assertSessionHasErrors('code');
        $this->patch("/admin/rooms/{$other->id}", $this->roomUpdate($other, ['code' => $room->code]))->assertSessionHasErrors('code');
        foreach (['create', 'update'] as $operation) {
            try {
                if ($operation === 'create') {
                    app(RoomService::class)->create($this->admin, $this->roomPayload());
                } else {
                    app(RoomService::class)->update($this->admin, $other, $this->roomUpdate($other, ['code' => $room->code]));
                }
                $this->fail('Constraint rooms_code_unique seharusnya menolak kode duplikat.');
            } catch (ValidationException $exception) {
                $this->assertSame(['code' => ['Kode kamar sudah digunakan.']], $exception->errors());
            }
        }
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_protected_room_fields_are_rejected_and_delete_is_unavailable(): void
    {
        $room = $this->room();
        foreach (['id' => (string) Str::uuid(), 'is_active' => false, 'room_type' => 'private', 'active_occupancy' => 0] as $field => $value) {
            $this->post('/admin/rooms', $this->roomPayload(changes: ['code' => 'UNIQUE', $field => $value]))->assertSessionHasErrors($field);
            $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, [$field => $value]))->assertSessionHasErrors($field);
        }
        $this->delete("/admin/rooms/{$room->id}")->assertStatus(405);
        $this->assertDatabaseCount('rooms', 1);
        $this->assertTrue($room->fresh()->is_active);
    }

    public function test_safe_same_class_edits_preserve_uuid_status_and_nonempty_history(): void
    {
        $room = $this->room();
        $this->stay($room);
        $this->stay($room, false);
        $camera = $room->camera()->create(['name' => 'Fixture', 'source_type' => 'webcam', 'source_reference' => 'private-fixture-source']);
        AiDetection::create(['camera_id' => $camera->id, 'external_event_id' => 'fixture-event', 'event_type' => 'fall', 'occurred_at' => now(), 'received_at' => now(), 'metadata' => ['private' => 'fixture']]);
        $before = $this->historySnapshot();
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['code' => 'RENAMED', 'name' => 'Nama baru', 'capacity' => 1]))
            ->assertSessionHasNoErrors()->assertRedirect("/admin/rooms/{$room->id}");
        $this->assertSame($before, $this->historySnapshot());
        $this->assertSame($room->id, $room->fresh()->id);
        $this->assertSame($room->service_class_id, $room->fresh()->service_class_id);
        $this->assertTrue($room->fresh()->is_active);
        $this->assertSame(1, $room->fresh()->capacity);
        $this->get("/admin/rooms/{$room->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Rooms/Show')->where('room.active_occupancy', 1)->where('room.has_stay_history', true)->where('room.has_camera', true)
            ->missing('room.camera')->missing('room.stays')->missing('room.service_class.monthly_price'));
        $this->get("/admin/rooms/{$room->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/Rooms/Edit')->where('room.has_camera', true));
        $this->getJson("/admin/rooms/{$room->id}", ['X-Inertia' => 'true'])->assertDontSee('private-fixture-source')->assertDontSee('Lansia Fixture')->assertDontSee('fixture-event');
    }

    public function test_fresh_occupancy_prevents_reduction_even_when_caller_cached_an_empty_count(): void
    {
        $room = $this->room()->loadCount('activeStays');
        $this->stay($room);
        $this->stay($room);
        $before = $this->historySnapshot();
        $this->assertSame(0, $room->active_stays_count);
        try {
            app(RoomService::class)->update($this->admin, $room, $this->roomUpdate($room, ['capacity' => 1]));
            $this->fail('Kapasitas di bawah hunian aktif seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }
        $this->assertSame(4, $room->fresh()->capacity);
        $this->assertSame($before, $this->historySnapshot());
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['capacity' => 2]))->assertSessionHasNoErrors();
        $this->assertSame(2, $room->fresh()->capacity);
    }

    public function test_class_change_requires_confirmation_active_target_and_fresh_reference(): void
    {
        $room = $this->room();
        $change = $this->roomUpdate($room, ['service_class_id' => $this->basic->id]);
        $this->patch("/admin/rooms/{$room->id}", $change)->assertSessionHasErrors('confirm_class_change');
        $this->basic->update(['is_active' => false]);
        $this->patch("/admin/rooms/{$room->id}", [...$change, 'confirm_class_change' => true])->assertSessionHasErrors('service_class_id');
        $this->basic->update(['is_active' => true]);
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['service_class_id' => $this->vip->id, 'confirm_class_change' => true]))->assertSessionHasErrors('capacity');
        $this->assertSame($this->shared->id, $room->fresh()->service_class_id);
        $this->patch("/admin/rooms/{$room->id}", [...$change, 'confirm_class_change' => true])->assertSessionHasNoErrors();
        $this->assertSame($this->basic->id, $room->fresh()->service_class_id);
        $this->patch("/admin/rooms/{$room->id}", [...$change, 'name' => 'Stale edit'])->assertSessionHasErrors('service_class_id');
        $this->assertSame('Kamar Uji', $room->fresh()->name);
        $this->assertSame($room->id, $room->fresh()->id);
    }

    public static function historicalDependencies(): array
    {
        return [['active_stay'], ['closed_stay'], ['camera'], ['inactive_camera']];
    }

    #[DataProvider('historicalDependencies')]
    public function test_every_stay_and_attached_camera_blocks_class_change(string $dependency): void
    {
        $room = $this->room();
        if (str_contains($dependency, 'stay')) {
            $this->stay($room, $dependency === 'active_stay');
        } else {
            $room->camera()->create(['name' => 'Fixture', 'source_type' => 'webcam', 'is_active' => $dependency === 'camera']);
        }
        $before = $this->historySnapshot();
        $roomBefore = $room->getAttributes();
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['service_class_id' => $this->basic->id, 'confirm_class_change' => true]))
            ->assertSessionHasErrors('service_class_id')->assertSessionDoesntHaveErrors(['capacity', 'confirm_class_change']);
        $this->assertSame($roomBefore, $room->fresh()->getAttributes());
        $this->assertSame($before, $this->historySnapshot());
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['name' => 'Edit aman']))->assertSessionHasNoErrors();
        $this->assertSame($before, $this->historySnapshot());
    }

    public static function blockingDependencies(): array
    {
        return [['active_stay'], ['camera'], ['inactive_camera']];
    }

    #[DataProvider('blockingDependencies')]
    public function test_each_active_stay_or_camera_blocks_room_deactivation(string $dependency): void
    {
        $room = $this->room();
        if ($dependency === 'active_stay') {
            $this->stay($room);
        } else {
            $room->camera()->create(['name' => 'Fixture', 'source_type' => 'webcam', 'is_active' => $dependency === 'camera']);
        }
        $before = $this->historySnapshot();
        $attributes = $room->getAttributes();
        $this->post("/admin/rooms/{$room->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->assertSame($attributes, $room->fresh()->getAttributes());
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_room_and_class_status_lifecycle_preserves_closed_history(): void
    {
        $room = $this->room();
        $this->stay($room, false);
        $before = $this->historySnapshot();
        $this->post("/admin/rooms/{$room->id}/deactivate", [])->assertSessionHasErrors('confirm_status_change');
        $this->post("/admin/rooms/{$room->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->post("/admin/rooms/{$room->id}/activate", ['confirm_status_change' => true])->assertSessionHasErrors('service_class_id');
        $this->assertFalse($room->fresh()->is_active);
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['name' => 'Edit kelas nonaktif']))->assertSessionHasNoErrors();
        $this->post("/admin/service-classes/{$this->shared->id}/activate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->assertFalse($room->fresh()->is_active);
        $this->post("/admin/rooms/{$room->id}/activate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->assertTrue($room->fresh()->is_active);
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_index_filters_literal_search_pagination_and_real_occupancy(): void
    {
        $this->get('/admin/rooms')->assertInertia(fn (Assert $page) => $page->component('Admin/Rooms/Index')->has('rooms.data', 0));
        $target = $this->room($this->basic, ['code' => 'TARGET_01', 'name' => 'Nama Unik', 'is_active' => false]);
        $this->stay($target);
        $this->stay($target, false);
        for ($i = 0; $i < 16; $i++) {
            $this->room($this->shared, ['code' => sprintf('ROOM-%02d', $i)]);
        }
        $this->get('/admin/rooms')->assertInertia(fn (Assert $page) => $page->has('rooms.data', 15)->where('rooms.total', 17));
        $this->get('/admin/rooms?page=2')->assertInertia(fn (Assert $page) => $page->has('rooms.data', 2)->where('rooms.current_page', 2));
        $this->get('/admin/rooms?search=nama&status=inactive&service_class_id='.$this->basic->id)->assertInertia(fn (Assert $page) => $page
            ->has('rooms.data', 1)->where('rooms.data.0.id', $target->id)->where('rooms.data.0.active_occupancy', 1));
        $this->get('/admin/rooms?search=target_')->assertInertia(fn (Assert $page) => $page->has('rooms.data', 1));
        $this->get('/admin/rooms?search=%25')->assertInertia(fn (Assert $page) => $page->has('rooms.data', 0));
        $this->get('/admin/rooms?status=active')->assertInertia(fn (Assert $page) => $page->where('rooms.total', 16));
        $this->get('/admin/rooms?service_class_id=bad-id')->assertSessionHasErrors('service_class_id');
        $this->get('/admin/rooms?status=unknown')->assertSessionHasErrors('status');
    }

    public function test_mutations_lock_class_rows_before_room_and_recheck_actor(): void
    {
        $room = $this->room();
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            if (str_contains($event->sql, 'for update')) {
                $queries[] = $event->sql;
            }
        });
        app(RoomService::class)->update($this->admin, $room, $this->roomUpdate($room, ['name' => 'Edit terkunci']));
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('"users"', $queries[0]);
        $this->assertStringContainsString('"service_classes"', $queries[1]);
        $this->assertStringContainsString('order by "id" asc', $queries[1]);
        $this->assertStringContainsString('"rooms"', $queries[2]);
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        try {
            app(RoomService::class)->update($this->admin, $room, $this->roomUpdate($room, ['name' => 'Dilarang']));
        } finally {
            $this->assertSame('Edit terkunci', $room->fresh()->name);
        }
    }
}
