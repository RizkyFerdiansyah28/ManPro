<?php

namespace Tests\Feature\Operations;

use App\Services\ResidentOperationsService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FacilityFeatureTestCase;

class OperationsLifecycleTest extends FacilityFeatureTestCase
{
    private function ready(): array
    {
        $elderly = $this->resident();
        $this->shared->update(['monthly_price' => '123.45']);
        $enrollment = $elderly->serviceEnrollments()->create(['service_class_id' => $this->shared->id, 'monthly_price' => '123.45', 'started_at' => now()->subDay()]);

        return [$elderly, $this->room(), $enrollment];
    }

    private function start($elderly, $room, $enrollment, array $changes = [])
    {
        return $this->post("/admin/residents/{$elderly->id}/stays", [...['room_id' => $room->id, 'enrollment_id' => $enrollment->id, 'confirm_start' => true], ...$changes]);
    }

    public function test_enrollment_precedes_stay_and_only_copies_locked_class_price(): void
    {
        $elderly = $this->resident();
        $this->shared->update(['monthly_price' => '999999999999.99']);
        $before = $this->shared->fresh()->getAttributes();
        $this->post("/admin/residents/{$elderly->id}/enrollments", ['service_class_id' => $this->shared->id, 'confirm_start' => true])->assertSessionHasNoErrors()->assertRedirect();
        $enrollment = $elderly->activeServiceEnrollment()->sole();
        $this->assertTrue(Str::isUuid($enrollment->id));
        $this->assertSame('999999999999.99', $enrollment->monthly_price);
        $this->assertNull($enrollment->ended_at);
        $this->assertSame(0, $elderly->stays()->count());
        $this->assertSame($before, $this->shared->fresh()->getAttributes());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $room = $this->room();
        $this->start($elderly, $room, $enrollment)->assertSessionHasNoErrors();
        $this->assertTrue(Str::isUuid($elderly->activeStay()->sole()->id));
        $this->assertSame($room->id, $elderly->activeStay()->sole()->room_id);
        $this->assertSame(1, $room->activeStays()->count());
    }

    public static function prices(): array
    {
        return [[null, false], ['0.00', true], ['12.34', true]];
    }

    #[DataProvider('prices')]
    public function test_null_pricing_is_rejected_but_configured_zero_and_decimals_are_accepted(?string $price, bool $allowed): void
    {
        $elderly = $this->resident();
        $this->shared->update(['monthly_price' => $price]);
        $response = $this->post("/admin/residents/{$elderly->id}/enrollments", ['service_class_id' => $this->shared->id, 'confirm_start' => true]);
        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($price, $elderly->activeServiceEnrollment()->sole()->monthly_price);
        } else {
            $response->assertSessionHasErrors('service_class_id');
            $this->assertSame(0, $elderly->serviceEnrollments()->count());
        }
        $this->assertDatabaseCount('invoices', 0);
    }

    public static function ineligibleStarts(): array
    {
        return [['resident', 'resident'], ['room', 'room_id'], ['class', 'service_class_id'], ['canonical', 'service_class_id'],
            ['missing_enrollment', 'enrollment_id'], ['foreign_enrollment', 'enrollment_id'], ['class_mismatch', 'room_id'],
            ['duplicate_stay', 'stay'], ['future_enrollment', 'enrollment_id'], ['full', 'room_id'], ['vip_capacity', 'room_id']];
    }

    #[DataProvider('ineligibleStarts')]
    public function test_each_stay_eligibility_failure_preserves_valid_unrelated_state(string $case, string $error): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        if ($case === 'resident') {
            $elderly->update(['is_active' => false]);
        }
        if ($case === 'room') {
            $room->update(['is_active' => false]);
        }
        if ($case === 'class') {
            $this->shared->update(['is_active' => false]);
        }
        if ($case === 'canonical') {
            $this->shared->update(['family_live_cctv' => true]);
        }
        if ($case === 'missing_enrollment') {
            $enrollment->update(['ended_at' => now()->subHour()]);
        }
        if ($case === 'foreign_enrollment') {
            $enrollment = $this->enrollment($this->shared);
        }
        if ($case === 'class_mismatch') {
            $enrollment->update(['service_class_id' => $this->basic->id]);
        }
        if ($case === 'future_enrollment') {
            $enrollment->update(['started_at' => now()->addDay()]);
        }
        if ($case === 'duplicate_stay') {
            $elderly->stays()->create(['room_id' => $room->id, 'started_at' => now()->subHour()]);
        }
        if ($case === 'full') {
            $room->update(['capacity' => 1]);
            $this->stay($room);
        }
        if ($case === 'vip_capacity') {
            $room->update(['service_class_id' => $this->vip->id, 'capacity' => 2]);
        }
        if ($case === 'vip_capacity') {
            $enrollment->update(['service_class_id' => $this->vip->id]);
        }
        $before = $this->historySnapshot();
        $this->start($elderly, $room, $enrollment)->assertSessionHasErrors($error);
        $this->assertSame($before, $this->historySnapshot());
    }

    public static function ineligibleEnrollments(): array
    {
        return [['inactive_resident', 'resident'], ['inactive_class', 'service_class_id'], ['drift', 'service_class_id'],
            ['duplicate', 'enrollment'], ['mismatch', 'service_class_id']];
    }

    #[DataProvider('ineligibleEnrollments')]
    public function test_enrollment_rechecks_activity_duplicates_and_active_room_class(string $case, string $error): void
    {
        $elderly = $this->resident();
        $this->shared->update(['monthly_price' => '123.45']);
        if ($case === 'inactive_resident') {
            $elderly->update(['is_active' => false]);
        }
        if ($case === 'inactive_class') {
            $this->shared->update(['is_active' => false]);
        }
        if ($case === 'drift') {
            $this->shared->update(['daily_gait_report' => true]);
        }
        if ($case === 'duplicate') {
            $elderly->serviceEnrollments()->create(['service_class_id' => $this->shared->id, 'monthly_price' => '123.45', 'started_at' => now()->subDay()]);
        }
        if ($case === 'mismatch') {
            $elderly->stays()->create(['room_id' => $this->room($this->basic)->id, 'started_at' => now()->subDay()]);
        }
        $before = $this->historySnapshot();
        $this->post("/admin/residents/{$elderly->id}/enrollments", ['service_class_id' => $this->shared->id, 'confirm_start' => true])->assertSessionHasErrors($error);
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_lifecycle_closes_separately_preserves_snapshot_billing_and_releases_guards(): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        $stay = $elderly->stays()->create(['room_id' => $room->id, 'started_at' => now()->subHour()]);
        $invoice = $enrollment->invoices()->create(['elderly_id' => $elderly->id, 'number' => 'OPERATIONS', 'billing_month' => '2026-01-01',
            'amount' => '123.45', 'due_date' => '2026-02-01', 'grace_period_ends_on' => '2026-02-04']);
        $payment = $invoice->payments()->create(['submitted_by' => $elderly->family_id, 'amount' => '123.45', 'proof_path' => 'private/fixture.png']);
        $billing = [$invoice->fresh()->getAttributes(), $payment->fresh()->getAttributes()];
        $this->patch("/admin/service-classes/{$this->shared->id}", ['name' => $this->shared->name, 'monthly_price' => '987.65'])->assertSessionHasNoErrors();
        $this->assertSame('123.45', $enrollment->fresh()->monthly_price);
        $root = "/admin/residents/{$elderly->id}";
        $this->post("{$root}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->post("/admin/rooms/{$room->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->post("/admin/service-classes/{$this->shared->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->post("{$root}/enrollments/{$enrollment->id}/close", ['confirm_end' => true])->assertSessionHasErrors('operation');
        $beforeStay = $stay->fresh()->getAttributes();
        $this->post("{$root}/stays/{$stay->id}/close", ['confirm_end' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($stay->fresh()->ended_at);
        foreach (['id', 'elderly_id', 'room_id', 'started_at', 'created_at'] as $field) {
            $this->assertSame($beforeStay[$field], $stay->fresh()->getRawOriginal($field));
        }
        $this->assertNull($enrollment->fresh()->ended_at);
        $this->assertSame(0, $room->activeStays()->count());
        $this->post("{$root}/stays/{$stay->id}/close", ['confirm_end' => true])->assertSessionHasErrors('operation');
        $this->post("{$root}/deactivate", ['confirm_status_change' => true])->assertSessionHasErrors('status');
        $this->post("{$root}/enrollments/{$enrollment->id}/close", ['confirm_end' => true])->assertSessionHasNoErrors();
        $closed = $enrollment->fresh()->getAttributes();
        $this->assertSame('123.45', $enrollment->fresh()->monthly_price);
        $this->post("{$root}/enrollments/{$enrollment->id}/close", ['confirm_end' => true])->assertSessionHasErrors('operation');
        $this->assertSame($closed, $enrollment->fresh()->getAttributes());
        $this->post("{$root}/deactivate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->post("/admin/rooms/{$room->id}/deactivate", ['confirm_status_change' => true])->assertSessionHasNoErrors();
        $this->assertSame($billing, [$invoice->fresh()->getAttributes(), $payment->fresh()->getAttributes()]);
        $this->assertDatabaseCount('elderly_stays', 1);
        $this->assertDatabaseCount('service_enrollments', 1);
        $this->assertSame($this->shared->id, $room->fresh()->service_class_id);
    }

    public function test_history_prevents_room_class_change_after_stay_ends(): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        $elderly->stays()->create(['room_id' => $room->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subHour()]);
        $before = $this->historySnapshot();
        $this->patch("/admin/rooms/{$room->id}", $this->roomUpdate($room, ['service_class_id' => $this->basic->id, 'confirm_class_change' => true]))->assertSessionHasErrors('service_class_id');
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_close_time_must_be_strictly_after_start_without_rewriting_history(): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        $this->freezeSecond();
        $stay = $elderly->stays()->create(['room_id' => $room->id, 'started_at' => now()]);
        $before = $stay->fresh()->getAttributes();
        $this->post("/admin/residents/{$elderly->id}/stays/{$stay->id}/close", ['confirm_end' => true])->assertSessionHasErrors('operation');
        $this->assertSame($before, $stay->fresh()->getAttributes());
        $this->travel(1)->seconds();
        $this->post("/admin/residents/{$elderly->id}/stays/{$stay->id}/close", ['confirm_end' => true])->assertSessionHasNoErrors();
    }

    public function test_forms_cannot_inject_prices_ownership_or_times_and_require_confirmation(): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        foreach (['monthly_price' => '0', 'elderly_id' => (string) Str::uuid(), 'started_at' => '2000-01-01', 'ended_at' => now()->toIso8601String()] as $field => $value) {
            $this->start($elderly, $room, $enrollment, [$field => $value])->assertSessionHasErrors($field);
        }
        $this->start($elderly, $room, $enrollment, ['confirm_start' => false])->assertSessionHasErrors('confirm_start');
        $this->assertDatabaseCount('elderly_stays', 0);
        $this->post("/admin/residents/{$elderly->id}/enrollments", ['service_class_id' => $this->shared->id, 'confirm_start' => true, 'monthly_price' => '0'])->assertSessionHasErrors('monthly_price');
        $this->assertDatabaseCount('service_enrollments', 1);
    }

    public function test_admin_views_current_state_and_paginated_history_without_sensitive_relations(): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        for ($i = 0; $i < 16; $i++) {
            $elderly->stays()->create(['room_id' => $room->id, 'started_at' => now()->subDays(20 - $i), 'ended_at' => now()->subDays(19 - $i)]);
            $elderly->serviceEnrollments()->create(['service_class_id' => $this->shared->id, 'monthly_price' => '12.34', 'started_at' => now()->subDays(20 - $i), 'ended_at' => now()->subDays(19 - $i)]);
        }
        $root = "/admin/residents/{$elderly->id}";
        $this->get($root)->assertInertia(fn (Assert $page) => $page->where('operations.stay', null)->where('operations.enrollment.id', $enrollment->id)->missing('operations.enrollment.invoices'));
        $this->get("{$root}/operations")->assertInertia(fn (Assert $page) => $page->component('Admin/Operations/Index')->has('stays.data', 15)->has('enrollments.data', 15)->where('stays.total', 16)->where('enrollments.total', 17)->missing('stays.data.0.room.camera'));
        $this->get("{$root}/operations?stays_page=2&enrollments_page=2")->assertInertia(fn (Assert $page) => $page->has('stays.data', 1)->has('enrollments.data', 2));
        $this->get("{$root}/operations?stays_page=0")->assertSessionHasErrors('stays_page');
        $this->get("{$root}/enrollments/create")->assertInertia(fn (Assert $page) => $page->component('Admin/Operations/CreateEnrollment')->has('classes', 3));
        $this->get("{$root}/stays/create?search=TEST")->assertInertia(fn (Assert $page) => $page->component('Admin/Operations/StartStay')->has('rooms.data', 1)->where('rooms.data.0.occupancy', 0)->where('rooms.data.0.available', 4));
        $this->get("{$root}/stays/create?search=%25")->assertInertia(fn (Assert $page) => $page->has('rooms.data', 0));
    }

    public function test_locked_state_overrides_stale_model_and_dependency_counts(): void
    {
        [$elderly, $room, $enrollment] = $this->ready();
        $room->loadCount('activeStays');
        $room->update(['capacity' => 1]);
        $this->stay($room);
        $before = $this->historySnapshot();
        try {
            app(ResidentOperationsService::class)->startStay($this->admin, $elderly, $room->id, $enrollment->id);
            $this->fail('Hunian aktual harus diperiksa ulang.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('room_id', $exception->errors());
        }
        $this->assertSame($before, $this->historySnapshot());
    }
}
