<?php

namespace Tests\Feature\Monitoring;

use App\Models\Invoice;
use App\Models\Notification;
use App\Models\ServiceEnrollment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\DevTools\DevTools;
use Inertia\DevTools\DevToolsServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MonitoringFeatureTestCase;

class MonitoringPrivacyTest extends MonitoringFeatureTestCase
{
    public static function staff(): array
    {
        return [['admin'], ['perawat']];
    }

    #[DataProvider('staff')]
    public function test_props_are_whitelisted_and_get_requests_preserve_all_domain_data(string $role): void
    {
        $this->monitorCamera->update(['source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        $known = $this->knownGait();
        $known->gaitObservation->elderly->family->update(['name' => 'PRIVATE-FAMILY-SENTINEL', 'email' => 'private-family@example.test']);
        $fall = $this->fall('resolved', resident: $this->resident());
        Notification::create(['type' => 'Fixture', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $this->admin->id,
            'fall_incident_id' => $fall->fallIncident->id, 'data' => ['secret' => 'PRIVATE-NOTIFICATION-SENTINEL']]);
        $this->actingAs($role === 'admin' ? $this->admin : $this->account('perawat'));
        $before = $this->monitoringSnapshot();
        foreach ([$role === 'admin' ? '/admin' : '/perawat', '/monitoring/records', '/monitoring/open-incidents'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $encoded = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);
            foreach (['fixture-secret', 'fixture-token', 'PRIVATE-GATEWAY-SENTINEL', 'PRIVATE-NOTES-SENTINEL', 'PRIVATE-FAMILY-SENTINEL',
                'private-family@example.test', 'PRIVATE-NOTIFICATION-SENTINEL', 'source_reference', 'external_event_id', 'metadata', 'payload_hash',
                'birth_date', 'monthly_price', 'responding_caregiver_id', 'verified_by', 'has_source_reference'] as $private) {
                $this->assertStringNotContainsString($private, $encoded);
            }
            $this->assertFalse($response->headers->has('X-Inertia-Devtools-Id'));
            if ($role === 'perawat') {
                $this->assertStringNotContainsString('resident_url', $encoded);
                $this->assertStringNotContainsString('configuration_url', $encoded);
                $this->assertStringNotContainsString('identity_elderly_id', $encoded);
            }
        }
        $this->assertNotSame(self::SOURCE, $this->monitorCamera->fresh()->getRawOriginal('source_reference'));
        $this->assertSame($before, $this->monitoringSnapshot());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_staff_safety_monitoring_is_not_gated_by_billing_benefits_or_active_configuration(): void
    {
        $known = $this->knownGait();
        $this->event('gait', [], ['category' => 'no_walking_detected']);
        $basicRoom = $this->room($this->basic);
        $basicCamera = $this->camera($basicRoom);
        $basicPeriod = $this->periodFor($basicCamera);
        $this->fall(changes: ['camera_id' => $basicCamera->id, 'camera_period_id' => $basicPeriod->id]);
        $enrollment = ServiceEnrollment::where('elderly_id', $known->gaitObservation->elderly_id)->sole();
        Invoice::create(['number' => 'MONITOR-OVERDUE', 'elderly_id' => $enrollment->elderly_id,
            'service_enrollment_id' => $enrollment->id, 'billing_month' => '2026-01-01', 'amount' => '123.45',
            'due_date' => '2026-01-05', 'grace_period_ends_on' => '2026-01-08']);
        $this->vip->update(['is_active' => false, 'family_live_cctv' => false, 'daily_gait_report' => false]);
        $this->basic->update(['is_active' => false, 'monthly_gait_report' => false]);
        config(['ai_gateway.enabled' => false]);
        $this->actingAs($this->account('perawat'));
        $before = $this->monitoringSnapshot();
        $rows = $this->listing()['records'];
        $this->assertSame(3, $rows['total']);
        $this->assertSame(['class_2', 'class_3', 'vip'], collect($rows['data'])->pluck('context.service_class.code')->sort()->values()->all());
        $this->assertSame(1, collect($rows['data'])->where('identity.state', 'known')->count());
        $this->assertSame($before, $this->monitoringSnapshot());
    }

    public function test_devtools_are_disabled_even_locally_and_old_archives_are_preserved(): void
    {
        $archives = fn () => collect(File::isDirectory(storage_path('inertia-devtools')) ? File::allFiles(storage_path('inertia-devtools')) : [])
            ->mapWithKeys(fn ($file) => [$file->getPathname() => hash_file('sha256', $file->getPathname())])->sortKeys()->all();
        $before = $archives();
        $original = app()->environment();
        try {
            foreach (['local', 'testing', 'production'] as $environment) {
                $this->app->instance('env', $environment);
                $this->assertFalse(DevTools::enabled());
                (new DevToolsServiceProvider($this->app))->boot();
                foreach ([null, 'keluarga', 'perawat', 'admin'] as $role) {
                    Auth::logout();
                    $this->app['session']->driver()->flush();
                    if ($role !== null) {
                        $this->actingAs($this->account($role));
                    }
                    foreach (['/_inertia/devtools/entries', '/_inertia/devtools/entries/01J00000000000000000000000'] as $path) {
                        $this->get($path)->assertNotFound();
                    }
                }
            }
        } finally {
            $this->app->instance('env', $original);
        }
        $this->assertSame($before, $archives());
    }

    public function test_infrastructure_failure_is_generic_not_zero_statistics_or_sql_disclosure(): void
    {
        $this->fall();
        $before = $this->monitoringSnapshot();
        DB::statement('SAVEPOINT monitor_failure');
        try {
            DB::statement('ALTER TABLE cameras RENAME TO monitor_unavailable');
            $response = $this->get('/admin')->assertStatus(503);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT monitor_failure');
        }
        $response->assertDontSee('SQLSTATE')->assertDontSee('monitor_unavailable')->assertDontSee('PRIVATE-GATEWAY');
        $this->assertSame($before, $this->monitoringSnapshot());
        $this->get('/admin')->assertOk();
    }
}
