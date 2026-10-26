<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MonitoringFeatureTestCase;

class MonitoringAuthorizationTest extends MonitoringFeatureTestCase
{
    public static function staff(): array
    {
        return [['admin', '/admin', 'Admin/Monitoring/Dashboard'], ['perawat', '/perawat', 'Caregiver/Monitoring/Dashboard']];
    }

    #[DataProvider('staff')]
    public function test_staff_can_monitor_without_gaining_management_access(string $role, string $home, string $component): void
    {
        $this->fall();
        $this->actingAs($role === 'admin' ? $this->admin : $this->account($role));
        $this->get($home)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->component($component));
        $this->get('/monitoring/records')->assertOk();
        $this->get('/monitoring/open-incidents')->assertOk();
        $this->assertTrue(Gate::allows('viewMonitoring'));
        if ($role === 'perawat') {
            $this->get('/admin/cameras/'.$this->monitorCamera->id)->assertForbidden();
            $this->get('/admin/residents/'.$this->resident()->id)->assertForbidden();
        }
    }

    public function test_guest_and_family_are_rejected_before_filter_lookups(): void
    {
        $this->actingAs($this->account('keluarga'));
        DB::enableQueryLog();
        foreach (['/monitoring/records', '/monitoring/open-incidents'] as $path) {
            $this->get($path.'?camera_id=not-a-uuid&secret=PRIVATE-INJECTED')->assertForbidden()->assertDontSee('PRIVATE-INJECTED');
        }
        $this->assertFalse(Gate::allows('viewMonitoring'));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains($q, 'from "cameras"') || str_contains($q, 'WITH clock')));
        Auth::logout();
        $this->app['session']->driver()->flush();
        foreach (['/admin', '/perawat', '/monitoring/records', '/monitoring/open-incidents'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->getJson('/monitoring/records')->assertUnauthorized();
    }

    #[DataProvider('staff')]
    public function test_inactive_existing_session_cannot_read_monitoring(string $role, string $home, string $component): void
    {
        $actor = $this->account($role);
        Auth::logout();
        $this->app['session']->driver()->flush();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $actor->email, 'password' => self::PASSWORD])->assertRedirect($home);
        $session = session()->all();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        foreach ([$home, '/monitoring/records', '/monitoring/open-incidents'] as $path) {
            $this->app['session']->driver()->flush();
            $this->withSession($session);
            Auth::forgetGuards();
            $this->get($path)->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_password_reset_and_logout_revoke_monitoring_access(): void
    {
        $actor = $this->account('perawat');
        Auth::logout();
        $this->app['session']->driver()->flush();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $actor->email, 'password' => self::PASSWORD])->assertRedirect('/perawat');
        $this->get('/monitoring/records')->assertOk();
        DB::table('users')->where('id', $actor->id)->update(['password' => bcrypt('NewPasswordForTest123')]);
        Auth::forgetGuards();
        $this->get('/monitoring/records')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $actor->email, 'password' => 'NewPasswordForTest123'])->assertRedirect('/perawat');
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/monitoring/open-incidents')->assertRedirect('/login');
    }
}
