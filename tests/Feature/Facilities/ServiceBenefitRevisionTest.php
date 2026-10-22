<?php

namespace Tests\Feature\Facilities;

use App\Models\ServiceClass;
use App\Support\CanonicalServiceClasses;
use Database\Seeders\ServiceClassSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FacilityFeatureTestCase;

class ServiceBenefitRevisionTest extends FacilityFeatureTestCase
{
    // Ekspektasi bisnis independen dari konstanta yang dipakai seeder/resource.
    public static function finalMatrix(): array
    {
        return [
            'VIP seluruh manfaat' => ['vip', ['room_type' => 'private', 'family_live_cctv' => true, 'instant_verified_emergency' => true,
                'verified_incident_summary' => true, 'monthly_gait_report' => true, 'weekly_gait_report' => true, 'daily_gait_report' => true]],
            'Kelas 2 mingguan bulanan' => ['class_2', ['room_type' => 'shared', 'family_live_cctv' => false, 'instant_verified_emergency' => true,
                'verified_incident_summary' => true, 'monthly_gait_report' => true, 'weekly_gait_report' => true, 'daily_gait_report' => false]],
            'Kelas 3 bulanan' => ['class_3', ['room_type' => 'shared', 'family_live_cctv' => false, 'instant_verified_emergency' => true,
                'verified_incident_summary' => true, 'monthly_gait_report' => true, 'weekly_gait_report' => false, 'daily_gait_report' => false]],
        ];
    }

    #[DataProvider('finalMatrix')]
    public function test_seeded_database_and_admin_props_match_final_business_matrix(string $code, array $expected): void
    {
        $class = ServiceClass::where('code', $code)->sole();
        $before = $class->getAttributes();
        foreach ($expected as $field => $value) {
            $this->assertSame($value, $class->getAttribute($field));
        }
        $this->assertTrue(CanonicalServiceClasses::matches($class));
        $this->get('/admin/service-classes')->assertInertia(function (Assert $page) use ($code, $expected) {
            $page->has('classes', 3)->where('classes', function ($classes) use ($code, $expected) {
                $item = collect($classes)->firstWhere('code', $code);
                foreach ($expected as $field => $value) {
                    $this->assertSame($value, $item[$field]);
                }

                return true;
            });
        });
        foreach (['' => 'Show', '/edit' => 'Edit'] as $suffix => $component) {
            $this->get("/admin/service-classes/{$class->id}{$suffix}")->assertInertia(function (Assert $page) use ($expected, $component) {
                $page->component('Admin/ServiceClasses/'.$component)->where('serviceClass.is_canonical', true)
                    ->where('serviceClass.staff_ai_monitoring', true);
                foreach ($expected as $field => $value) {
                    $page->where('serviceClass.'.$field, $value)->where('serviceClass.approved_benefits.'.$field, $value);
                }
            });
        }
        $this->assertSame($before, $class->fresh()->getAttributes());
    }

    #[DataProvider('finalMatrix')]
    public function test_every_benefit_is_protected_on_edit_and_status_forms(string $code, array $expected): void
    {
        $class = ServiceClass::where('code', $code)->sole();
        $before = $class->getAttributes();
        foreach ([...$expected, 'staff_ai_monitoring' => true, 'approved_benefits' => $expected] as $field => $value) {
            $forged = is_bool($value) ? ! $value : ($field === 'room_type' ? ($value === 'private' ? 'shared' : 'private') : []);
            $this->patch("/admin/service-classes/{$class->id}", ['name' => $class->name, 'monthly_price' => $class->monthly_price, $field => $forged])
                ->assertSessionHasErrors($field)->assertSessionDoesntHaveErrors(['name', 'monthly_price']);
            $this->post("/admin/service-classes/{$class->id}/deactivate", ['confirm_status_change' => true, $field => $forged])
                ->assertSessionHasErrors($field)->assertSessionDoesntHaveErrors(['confirm_status_change', 'status']);
            $this->assertSame($before, $class->fresh()->getAttributes());
        }
    }

    public function test_rerunning_seeder_does_not_synchronize_existing_legacy_flags_or_modify_prices_names_and_history(): void
    {
        $room = $this->room();
        $this->stay($room, false);
        $enrollment = $this->enrollment($this->shared);
        $invoice = $enrollment->invoices()->create(['elderly_id' => $enrollment->elderly_id, 'number' => 'BENEFIT-SNAPSHOT',
            'billing_month' => '2026-01-01', 'amount' => '123.45', 'due_date' => '2026-02-01', 'grace_period_ends_on' => '2026-02-04']);
        $invoice->payments()->create(['submitted_by' => $enrollment->elderly->family_id, 'amount' => '123.45', 'proof_path' => 'private/fixture.png']);
        $this->vip->update(['weekly_gait_report' => false, 'monthly_gait_report' => false, 'name' => 'VIP Konfigurasi Admin', 'monthly_price' => '999.99']);
        $this->shared->update(['daily_gait_report' => true, 'weekly_gait_report' => false, 'monthly_gait_report' => false, 'name' => 'Kelas 2 Konfigurasi Admin', 'monthly_price' => '456.78']);
        $this->basic->update(['instant_verified_emergency' => false, 'weekly_gait_report' => true, 'name' => 'Kelas 3 Konfigurasi Admin', 'monthly_price' => null, 'is_active' => false]);
        $classes = ServiceClass::orderBy('code')->get()->map->getAttributes()->all();
        $history = $this->historySnapshot();
        $roomBefore = $room->getAttributes();
        $this->seed(ServiceClassSeeder::class);
        $this->seed(ServiceClassSeeder::class);
        $this->assertSame($classes, ServiceClass::orderBy('code')->get()->map->getAttributes()->all());
        $this->assertSame($history, $this->historySnapshot());
        $this->assertSame($roomBefore, $room->fresh()->getAttributes());
        $this->assertSame('123.45', $enrollment->fresh()->monthly_price);
        $this->assertSame('123.45', $invoice->fresh()->amount);
        $this->assertDatabaseCount('service_classes', 3);
        $this->assertDatabaseCount('users', 2);
        foreach ([$this->vip, $this->shared, $this->basic] as $class) {
            $this->assertFalse(CanonicalServiceClasses::matches($class->fresh()));
            $this->get("/admin/service-classes/{$class->id}")->assertInertia(fn (Assert $page) => $page
                ->where('serviceClass.is_canonical', false)->where('serviceClass.staff_ai_monitoring', true));
        }
        // Penolakan berasal dari drift konfigurasi, bukan validasi harga/nama atau fixture tidak valid.
        $this->patch("/admin/service-classes/{$this->vip->id}", ['name' => $this->vip->name, 'monthly_price' => $this->vip->monthly_price])
            ->assertSessionHasErrors('service_class_id')->assertSessionDoesntHaveErrors(['name', 'monthly_price']);
        $this->assertSame($classes, ServiceClass::orderBy('code')->get()->map->getAttributes()->all());
        $this->assertSame($history, $this->historySnapshot());
    }

    public function test_staff_monitoring_is_global_despite_class_price_status_and_unpaid_invoice(): void
    {
        $enrollment = $this->enrollment($this->basic);
        $invoice = $enrollment->invoices()->create(['elderly_id' => $enrollment->elderly_id, 'number' => 'UNPAID-MONITORING',
            'billing_month' => '2026-01-01', 'amount' => '123.45', 'due_date' => '2026-02-01', 'grace_period_ends_on' => '2026-02-04']);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->vip->update(['monthly_price' => null]);
        $this->shared->update(['monthly_price' => '0.00']);
        $this->basic->update(['monthly_price' => '999.99', 'is_active' => false]);
        $history = $this->historySnapshot();
        foreach ([$this->vip, $this->shared, $this->basic] as $class) {
            $this->get("/admin/service-classes/{$class->id}")->assertInertia(fn (Assert $page) => $page
                ->where('serviceClass.staff_ai_monitoring', true)->where('serviceClass.is_canonical', true));
        }
        $this->assertSame($history, $this->historySnapshot());
    }
}
