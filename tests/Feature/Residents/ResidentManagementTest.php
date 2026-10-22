<?php

namespace Tests\Feature\Residents;

use App\Models\Elderly;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\User;
use App\Services\ResidentService;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresFeatureTestCase;

class ResidentManagementTest extends PostgresFeatureTestCase
{
    private User $admin;

    private User $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->account();
        $this->family = $this->account('keluarga');
        $this->actingAs($this->admin);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Lansia Uji', 'birth_date' => '1950-06-15', 'family_id' => $this->family->id], $changes);
    }

    private function resident(array $changes = []): Elderly
    {
        return Elderly::create([...$this->payload(), ...$changes]);
    }

    public function test_admin_can_create_multiple_residents_with_one_family_and_stable_uuid(): void
    {
        $this->get('/admin/residents/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Residents/Create')->missing('families')->missing('accounts'));
        for ($i = 0; $i < 2; $i++) {
            $this->post('/admin/residents', $this->payload(['name' => "  Lansia {$i}  ", 'family_id' => strtoupper($this->family->id)]))
                ->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertDatabaseCount('elderlies', 2);
        foreach ($this->family->elderlies()->get() as $elderly) {
            $this->assertTrue(Str::isUuid($elderly->id));
            $this->assertTrue($elderly->is_active);
            $this->assertSame(trim($elderly->name), $elderly->name);
            $id = $elderly->id;
            $this->get("/admin/residents/{$id}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Residents/Show')->where('resident.id', $id)->where('resident.family.id', $this->family->id));
            $this->assertSame($id, $elderly->fresh()->id);
        }
    }

    public static function invalidFields(): array
    {
        return ['empty name' => ['name', ''], 'name array' => ['name', ['invalid']], 'name too long' => ['name', str_repeat('a', 256)],
            'invalid date' => ['birth_date', '1950-02-30'], 'future date' => ['birth_date', 'future'],
            'timestamp' => ['birth_date', '1950-06-15T00:00:00Z'], 'missing family' => ['family_id', null],
            'year zero' => ['birth_date', '0000-01-01'],
            'bad uuid' => ['family_id', 'not-uuid'], 'nonexistent uuid' => ['family_id', '00000000-0000-4000-8000-000000000001']];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_resident_input_is_rejected_in_isolation(string $field, mixed $value): void
    {
        if ($value === 'future') {
            $value = today()->addDay()->format('Y-m-d');
        }
        $this->post('/admin/residents', $this->payload([$field => $value]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('elderlies', 0);
    }

    public static function ineligibleFamilies(): array
    {
        return [['admin', true], ['perawat', true], ['keluarga', false]];
    }

    #[DataProvider('ineligibleFamilies')]
    public function test_create_and_reassignment_reject_ineligible_family(string $role, bool $active): void
    {
        $invalid = $this->account($role, $active);
        $this->post('/admin/residents', $this->payload(['family_id' => $invalid->id]))->assertSessionHasErrors('family_id');
        $this->assertDatabaseCount('elderlies', 0);
        $elderly = $this->resident()->fresh();
        $before = $elderly->getAttributes();
        $this->post("/admin/residents/{$elderly->id}/family", ['family_id' => $invalid->id,
            'current_family_id' => $this->family->id, 'confirm_reassignment' => true])->assertSessionHasErrors('family_id');
        $this->assertSame($before, $elderly->fresh()->getAttributes());
    }

    public function test_update_changes_only_biodata_and_keeps_inactive_family_reference(): void
    {
        $elderly = $this->resident()->fresh();
        $id = $elderly->id;
        User::whereKey($this->family->id)->update(['is_active' => false]);
        $this->get("/admin/residents/{$id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Residents/Edit')->where('resident.family.is_active', false)->missing('families'));
        $this->patch("/admin/residents/{$id}", ['name' => 'Nama Baru', 'birth_date' => '1949-05-20'])
            ->assertSessionHasNoErrors()->assertRedirect("/admin/residents/{$id}");
        $elderly->refresh();
        $this->assertSame('Nama Baru', $elderly->name);
        $this->assertSame('1949-05-20', $elderly->birth_date->format('Y-m-d'));
        $this->assertSame($this->family->id, $elderly->family_id);
        $this->assertTrue($elderly->is_active);
        $this->assertSame($id, $elderly->id);
    }

    public static function protectedFields(): array
    {
        return [['is_active', false], ['id', '00000000-0000-4000-8000-000000000000'], ['age', 75]];
    }

    #[DataProvider('protectedFields')]
    public function test_server_controlled_fields_cannot_be_injected_on_create(string $field, mixed $value): void
    {
        $this->post('/admin/residents', $this->payload([$field => $value]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('elderlies', 0);
    }

    public function test_ordinary_edit_cannot_reassign_or_change_status(): void
    {
        $elderly = $this->resident()->fresh();
        $before = $elderly->getAttributes();
        $other = $this->account('keluarga');
        $this->patch("/admin/residents/{$elderly->id}", ['name' => 'Berubah', 'birth_date' => '1949-01-01',
            'family_id' => $other->id, 'is_active' => false])->assertSessionHasErrors(['family_id', 'is_active']);
        $this->assertSame($before, $elderly->fresh()->getAttributes());
    }

    public function test_explicit_reassignment_requires_confirmation_and_rejects_stale_or_same_family(): void
    {
        $elderly = $this->resident()->fresh();
        $before = $elderly->getAttributes();
        $next = $this->account('keluarga');
        $base = ['family_id' => $next->id, 'current_family_id' => $this->family->id];
        $this->post("/admin/residents/{$elderly->id}/family", $base)->assertSessionHasErrors('confirm_reassignment');
        $this->assertSame($before, $elderly->fresh()->getAttributes());
        $this->post("/admin/residents/{$elderly->id}/family", [...$base, 'confirm_reassignment' => true,
            'current_family_id' => $next->id])->assertSessionHasErrors('family_id');
        $this->assertSame($before, $elderly->fresh()->getAttributes());
        $this->post("/admin/residents/{$elderly->id}/family", [...$base, 'confirm_reassignment' => true,
            'family_id' => $this->family->id])->assertSessionHasErrors('family_id');
        $this->assertSame($before, $elderly->fresh()->getAttributes());
        $this->post("/admin/residents/{$elderly->id}/family", [...$base, 'confirm_reassignment' => true])
            ->assertSessionHasNoErrors()->assertRedirect("/admin/residents/{$elderly->id}");
        $updated = $elderly->fresh();
        $this->assertSame($next->id, $updated->family_id);
        foreach (['id', 'name', 'birth_date', 'is_active', 'created_at'] as $key) {
            $this->assertSame($before[$key], $updated->getRawOriginal($key));
        }
    }

    public function test_status_changes_require_confirmation_preserve_identity_and_never_delete(): void
    {
        $elderly = $this->resident()->fresh();
        $before = $elderly->getAttributes();
        $url = "/admin/residents/{$elderly->id}";
        $this->post("{$url}/deactivate", [])->assertSessionHasErrors('confirm_status_change');
        $this->post("{$url}/deactivate", ['confirm_status_change' => true, 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->assertSame($before, $elderly->fresh()->getAttributes());
        $this->post("{$url}/deactivate", ['confirm_status_change' => true])->assertRedirect($url);
        $this->assertDatabaseHas('elderlies', ['id' => $elderly->id, 'family_id' => $this->family->id, 'is_active' => false]);
        $this->post("{$url}/activate", ['confirm_status_change' => true])->assertRedirect($url);
        $updated = $elderly->fresh();
        $this->assertTrue($updated->is_active);
        foreach (['id', 'name', 'birth_date', 'family_id', 'created_at'] as $key) {
            $this->assertSame($before[$key], $updated->getRawOriginal($key));
        }
        $this->assertDatabaseCount('elderlies', 1);
        $this->delete($url)->assertStatus(405);
        $this->assertDatabaseHas('elderlies', ['id' => $elderly->id]);
    }

    public static function activeRelationships(): array
    {
        return [['stay'], ['enrollment'], ['both']];
    }

    #[DataProvider('activeRelationships')]
    public function test_deactivation_with_active_relationship_is_rejected_without_history_changes(string $kind): void
    {
        $elderly = $this->resident()->fresh();
        $this->seed(ServiceClassSeeder::class);
        $class = DB::table('service_classes')->where('code', 'vip')->first();
        if ($kind !== 'enrollment') {
            $room = Room::create(['service_class_id' => $class->id, 'code' => 'TEST', 'name' => 'Uji', 'capacity' => 1]);
            $elderly->stays()->create(['room_id' => $room->id, 'started_at' => '2026-01-01T00:00:00+00:00']);
        }
        if ($kind !== 'stay') {
            $elderly->serviceEnrollments()->create(['service_class_id' => $class->id, 'started_at' => '2026-01-01T00:00:00+00:00']);
        }
        $before = $this->historySnapshot();
        $this->post("/admin/residents/{$elderly->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->assertTrue($elderly->fresh()->is_active);
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_history_is_preserved_through_status_edit_and_family_reassignment(): void
    {
        $elderly = $this->resident();
        $this->seed(ServiceClassSeeder::class);
        $class = ServiceClass::where('code', 'vip')->firstOrFail();
        $room = $class->rooms()->create(['code' => 'HIST', 'name' => 'Uji', 'capacity' => 1]);
        $stay = $elderly->stays()->create(['room_id' => $room->id, 'started_at' => '2026-01-01T00:00:00+00:00', 'ended_at' => '2026-02-01T00:00:00+00:00']);
        $enrollment = $elderly->serviceEnrollments()->create(['service_class_id' => $class->id,
            'started_at' => '2026-01-01T00:00:00+00:00', 'ended_at' => '2026-02-01T00:00:00+00:00']);
        $invoice = $elderly->invoices()->create(['service_enrollment_id' => $enrollment->id, 'number' => 'HIST-UJI',
            'billing_month' => '2026-01-01', 'amount' => 10, 'due_date' => '2026-02-01', 'grace_period_ends_on' => '2026-02-04']);
        $invoice->payments()->create(['submitted_by' => $this->family->id, 'amount' => 10, 'proof_path' => 'private/fixture.png']);
        $camera = $room->camera()->create(['name' => 'Fixture', 'source_type' => 'webcam']);
        $detection = $camera->aiDetections()->create(['external_event_id' => 'fixture-gait', 'event_type' => 'gait', 'occurred_at' => '2026-01-15T00:00:00+00:00']);
        $elderly->gaitObservations()->create(['ai_detection_id' => $detection->id, 'category' => 'walking_normal', 'identity_basis' => 'vip_room_single_resident']);
        $fall = $camera->aiDetections()->create(['external_event_id' => 'fixture-fall', 'event_type' => 'fall', 'occurred_at' => '2026-01-15T00:00:00+00:00']);
        $caregiver = $this->account('perawat');
        $elderly->fallIncidents()->create(['ai_detection_id' => $fall->id, 'status' => 'resolved', 'responding_caregiver_id' => $caregiver->id,
            'acknowledged_at' => '2026-01-15T00:01:00+00:00', 'verified_by' => $caregiver->id, 'verified_at' => '2026-01-15T00:02:00+00:00', 'resolved_at' => '2026-01-15T00:03:00+00:00']);
        $before = $this->historySnapshot();
        $this->post("/admin/residents/{$elderly->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->patch("/admin/residents/{$elderly->id}", ['name' => 'Nama Baru', 'birth_date' => '1948-01-01'])->assertSessionHasNoErrors();
        $next = $this->account('keluarga');
        $this->post("/admin/residents/{$elderly->id}/family", ['family_id' => $next->id, 'current_family_id' => $this->family->id, 'confirm_reassignment' => true])->assertSessionHasNoErrors();
        $this->assertSame($before, $this->historySnapshot());
        $this->assertDatabaseHas('elderlies', ['id' => $elderly->id, 'family_id' => $next->id, 'is_active' => false]);
    }

    private function historySnapshot(): array
    {
        $result = [];
        foreach (['elderly_stays', 'service_enrollments', 'ai_detections', 'gait_observations', 'fall_incidents', 'invoices', 'payments'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public function test_family_selector_is_bounded_searchable_and_ineligible_users_never_appear(): void
    {
        User::factory()->count(17)->create(['role' => 'keluarga', 'is_active' => true]);
        $inactive = $this->account('keluarga', false);
        $caregiver = $this->account('perawat');
        $this->getJson('/admin/family-options')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('total', 18)
            ->assertJsonMissing(['id' => $inactive->id])->assertJsonMissing(['id' => $caregiver->id])->assertJsonMissingPath('data.0.password');
        $this->getJson('/admin/family-options?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/admin/family-options?'.http_build_query(['search' => strtoupper($this->family->email)]))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->family->id);
        $this->getJson('/admin/family-options?role=admin&is_active=0')->assertUnprocessable()->assertJsonValidationErrors(['role', 'is_active']);
        $this->getJson('/admin/family-options?search=%25')->assertJsonCount(0, 'data');
    }

    public function test_admin_list_search_status_pagination_and_serialization(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->resident(['name' => "Lansia Uji {$i}"]);
        }
        $target = $this->resident(['name' => 'Nama Unik', 'is_active' => false]);
        $this->get('/admin/residents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Residents/Index')->has('residents.data', 15)->where('residents.total', 17)
            ->missing('residents.data.0.family.password')->missing('residents.data.0.family.remember_token')
            ->missing('residents.data.0.invoices')->missing('residents.data.0.stays'));
        $this->get('/admin/residents?page=2')->assertInertia(fn (Assert $page) => $page->has('residents.data', 2));
        $this->get('/admin/residents?search=nama&status=inactive')->assertInertia(fn (Assert $page) => $page
            ->has('residents.data', 1)->where('residents.data.0.id', $target->id));
        $this->get('/admin/residents?search=0&status=inactive')->assertInertia(fn (Assert $page) => $page->has('residents.data', 0));
        $this->get('/admin/residents?search=%25')->assertInertia(fn (Assert $page) => $page->has('residents.data', 0));
        $this->get('/admin/residents?status=invalid&page=0')->assertSessionHasErrors(['status', 'page']);
    }

    public function test_service_rechecks_family_eligibility_after_request_validation(): void
    {
        $data = $this->payload();
        User::whereKey($this->family->id)->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        try {
            app(ResidentService::class)->create($this->admin, $data);
        } finally {
            $this->assertDatabaseCount('elderlies', 0);
        }
    }

    public function test_service_rechecks_admin_authority_on_current_database_state(): void
    {
        User::whereKey($this->admin->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        try {
            app(ResidentService::class)->create($this->admin, $this->payload());
        } finally {
            $this->assertDatabaseCount('elderlies', 0);
        }
    }

    public static function birthdays(): array
    {
        return [['2026-06-14', '1950-06-15', 75], ['2026-06-15', '1950-06-15', 76],
            ['2026-06-16', '1950-06-15', 76], ['2026-06-15', '2026-06-15', 0]];
    }

    #[DataProvider('birthdays')]
    public function test_age_is_calculated_from_birth_date_and_never_stored(string $today, string $birth, int $expected): void
    {
        $this->travelTo(new \DateTimeImmutable($today.' 12:00:00'));
        $elderly = $this->resident(['birth_date' => $birth]);
        $this->assertSame($expected, $elderly->age);
        $this->assertArrayNotHasKey('age', $elderly->getAttributes());
        $this->get("/admin/residents/{$elderly->id}")->assertInertia(fn (Assert $page) => $page->where('resident.age', $expected));
    }

    public function test_invalid_and_nonexistent_uuid_are_404_for_admin(): void
    {
        foreach (['invalid', (string) Str::uuid()] as $id) {
            $this->get("/admin/residents/{$id}")->assertNotFound();
            $this->patch("/admin/residents/{$id}", ['name' => 'Uji', 'birth_date' => '1950-01-01'])->assertNotFound();
        }
    }
}
