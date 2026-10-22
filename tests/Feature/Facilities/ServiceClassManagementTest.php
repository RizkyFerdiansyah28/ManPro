<?php

namespace Tests\Feature\Facilities;

use App\Rules\MonthlyPrice;
use App\Services\ServiceClassService;
use App\Support\CanonicalServiceClasses;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FacilityFeatureTestCase;

class ServiceClassManagementTest extends FacilityFeatureTestCase
{
    public function test_admin_lists_views_and_edits_canonical_service_classes(): void
    {
        $this->get('/admin/service-classes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ServiceClasses/Index')->has('classes', 3)->where('classes.0.monthly_price', null));
        foreach ([$this->vip, $this->shared, $this->basic] as $class) {
            $this->assertTrue(CanonicalServiceClasses::matches($class));
            $this->get("/admin/service-classes/{$class->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ServiceClasses/Show')->where('serviceClass.id', $class->id)
                ->where('serviceClass.is_canonical', true)->missing('serviceClass.service_enrollments'));
            $this->get("/admin/service-classes/{$class->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/ServiceClasses/Edit'));
        }
        $this->post('/admin/service-classes', ['code' => 'new-class'])->assertStatus(405);
        $this->delete("/admin/service-classes/{$this->vip->id}")->assertStatus(405);
        $this->assertDatabaseCount('service_classes', 3);
    }

    public static function validPrices(): array
    {
        return [[null, null], ['', null], ['0', '0.00'], [0, '0.00'], ['12.34', '12.34'], [12.25, '12.25'],
            ['999999999999.99', '999999999999.99']];
    }

    #[DataProvider('validPrices')]
    public function test_permitted_class_updates_accept_nullable_exact_prices_and_preserve_semantics(mixed $price, ?string $expected): void
    {
        $before = $this->shared->getAttributes();
        $this->patch("/admin/service-classes/{$this->shared->id}", ['name' => '  Nama Kelas Baru  ', 'monthly_price' => $price])
            ->assertSessionHasNoErrors()->assertRedirect("/admin/service-classes/{$this->shared->id}");
        $updated = $this->shared->fresh();
        $this->assertSame('Nama Kelas Baru', $updated->name);
        $this->assertSame($expected, $updated->monthly_price);
        foreach (['id', 'code', 'room_type', 'is_active', ...array_keys(CanonicalServiceClasses::DEFINITIONS['vip'])] as $key) {
            $this->assertSame($before[$key], $updated->getRawOriginal($key));
        }
    }

    public static function invalidPrices(): array
    {
        return [['-1'], ['-0.01'], ['NaN'], ['nan'], ['Infinity'], ['-Infinity'], ['inf'], ['1e3'], ['1,00'],
            ['12.345'], ['1000000000000'], ['999999999999.999'], [true], [[]], ['not-a-price'], [0.30000000000000004]];
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_prices_are_rejected_without_changing_any_class_field(mixed $price): void
    {
        $before = $this->vip->fresh()->getAttributes();
        $this->patch("/admin/service-classes/{$this->vip->id}", ['name' => 'Nama Valid', 'monthly_price' => $price])
            ->assertSessionHasErrors('monthly_price');
        $this->assertSame($before, $this->vip->fresh()->getAttributes());
    }

    public function test_nonfinite_php_numbers_are_rejected_by_shared_price_validation(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            $validator = Validator::make(['monthly_price' => $value], ['monthly_price' => [new MonthlyPrice]]);
            $this->assertTrue($validator->fails());
            $this->assertTrue($validator->errors()->has('monthly_price'));
        }
    }

    public static function immutableFields(): array
    {
        return [['code', 'class_3'], ['code', ''], ['room_type', 'shared'], ['family_live_cctv', false],
            ['instant_verified_emergency', null], ['daily_gait_report', false], ['weekly_gait_report', true],
            ['monthly_gait_report', true], ['verified_incident_summary', false], ['is_active', false], ['id', 'forged-id']];
    }

    #[DataProvider('immutableFields')]
    public function test_protected_class_configuration_rejects_injected_fields(string $field, mixed $value): void
    {
        $before = $this->vip->fresh()->getAttributes();
        $this->patch("/admin/service-classes/{$this->vip->id}", ['name' => 'Nama Valid', 'monthly_price' => null, $field => $value])
            ->assertSessionHasErrors($field);
        $this->assertSame($before, $this->vip->fresh()->getAttributes());
    }

    public function test_shared_cctv_cannot_be_enabled_and_class_three_instant_notifications_cannot_be_disabled(): void
    {
        foreach ([[$this->shared, 'family_live_cctv', true], [$this->basic, 'instant_verified_emergency', false]] as [$class, $flag, $value]) {
            $this->patch("/admin/service-classes/{$class->id}", ['name' => $class->name, 'monthly_price' => null, $flag => $value])
                ->assertSessionHasErrors($flag);
            $this->assertSame(! $value, $class->fresh()->getAttribute($flag));
        }
    }

    public function test_price_updates_preserve_enrollment_invoice_payment_and_seeder_idempotency(): void
    {
        $enrollment = $this->enrollment($this->shared);
        $invoice = $enrollment->invoices()->create(['elderly_id' => $enrollment->elderly_id, 'number' => 'SNAPSHOT',
            'billing_month' => '2026-01-01', 'amount' => '123.45', 'due_date' => '2026-02-01', 'grace_period_ends_on' => '2026-02-04']);
        $invoice->payments()->create(['submitted_by' => $enrollment->elderly->family_id, 'amount' => '123.45', 'proof_path' => 'private/fixture.png']);
        $before = $this->historySnapshot();
        $this->patch("/admin/service-classes/{$this->shared->id}", ['name' => 'Kelas Diperbarui', 'monthly_price' => '987.65'])
            ->assertSessionHasNoErrors();
        $classBeforeSeed = $this->shared->fresh()->getAttributes();
        $this->seed(ServiceClassSeeder::class);
        $this->assertSame($before, $this->historySnapshot());
        $this->assertSame('123.45', $enrollment->fresh()->monthly_price);
        $this->assertSame('123.45', $invoice->fresh()->amount);
        $this->assertSame($classBeforeSeed, $this->shared->fresh()->getAttributes());
        $this->assertDatabaseCount('service_classes', 3);
        $this->assertDatabaseCount('users', 2);
    }

    public static function dependencies(): array
    {
        return [['active_room'], ['active_stay'], ['active_enrollment'], ['camera'], ['inactive_camera']];
    }

    #[DataProvider('dependencies')]
    public function test_class_deactivation_rejects_each_dependency_without_mutation(string $dependency): void
    {
        $room = null;
        if ($dependency !== 'active_enrollment') {
            $room = $this->room($this->shared, ['is_active' => $dependency === 'active_room']);
        }
        if ($dependency === 'active_stay') {
            $this->stay($room);
        }
        if ($dependency === 'active_enrollment') {
            $this->enrollment($this->shared);
        }
        if (in_array($dependency, ['camera', 'inactive_camera'], true)) {
            $room->camera()->create(['name' => 'Fixture', 'source_type' => 'webcam', 'is_active' => $dependency === 'camera', 'source_reference' => 'fixture-private-reference']);
        }
        $before = $this->historySnapshot();
        $classBefore = $this->shared->fresh()->getAttributes();
        $roomBefore = $room?->fresh()->getAttributes();
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->assertSame($classBefore, $this->shared->fresh()->getAttributes());
        $this->assertSame($roomBefore, $room?->fresh()->getAttributes());
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_class_with_only_inactive_rooms_and_closed_history_can_change_status(): void
    {
        $room = $this->room($this->shared, ['is_active' => false]);
        $this->stay($room, false);
        $this->enrollment($this->shared, false);
        $before = $this->historySnapshot();
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", [])->assertSessionHasErrors('confirm_status_change');
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", ['confirm_status_change' => true, 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->assertFalse($this->shared->fresh()->is_active);
        $this->assertFalse($room->fresh()->is_active);
        $this->post("/admin/service-classes/{$this->shared->id}/activate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->shared->fresh()->is_active);
        $this->assertFalse($room->fresh()->is_active);
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_canonical_drift_is_displayed_honestly_and_mutations_fail_closed(): void
    {
        DB::table('service_classes')->where('id', $this->basic->id)->update(['instant_verified_emergency' => false]);
        $this->get("/admin/service-classes/{$this->basic->id}")->assertInertia(fn (Assert $page) => $page
            ->where('serviceClass.is_canonical', false)->where('serviceClass.instant_verified_emergency', false)
            ->where('serviceClass.approved_benefits.instant_verified_emergency', true));
        $this->patch("/admin/service-classes/{$this->basic->id}", ['name' => 'Nama Valid', 'monthly_price' => null])->assertSessionHasErrors('service_class_id');
        $this->post('/admin/rooms', $this->roomPayload($this->basic))->assertSessionHasErrors('service_class_id');
    }

    public function test_service_rechecks_fresh_dependencies_instead_of_cached_class_relationships(): void
    {
        $this->shared->load('rooms', 'serviceEnrollments');
        $room = $this->room($this->shared);
        $this->expectException(ValidationException::class);
        try {
            app(ServiceClassService::class)->setActive($this->admin, $this->shared, false);
        } finally {
            $this->assertTrue($this->shared->fresh()->is_active);
            $this->assertTrue($room->fresh()->is_active);
        }
    }
}
