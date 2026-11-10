<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NotificationFeatureTestCase;

class NotificationAccessTest extends NotificationFeatureTestCase
{
    public static function roles(): array
    {
        return [['admin', true, 200], ['perawat', true, 200], ['keluarga', true, 403],
            ['admin', false, 302], ['perawat', false, 302], [null, true, 302]];
    }

    #[DataProvider('roles')]
    public function test_routes_respect_active_role_and_own_scope(?string $role, bool $active, int $expected): void
    {
        $actor = $role ? $this->account($role, $active) : null;
        foreach (['/notifications', '/notifications/summary'] as $path) {
            Auth::forgetGuards();
            session()->flush();
            if ($actor) {
                $this->signIn($actor);
            }
            $this->get($path)->assertStatus($expected);
        }
        foreach (['/notifications/'.Str::uuid().'/read', '/notifications/read-all'] as $path) {
            Auth::forgetGuards();
            session()->flush();
            if ($actor) {
                $this->signIn($actor);
            }
            $this->post($path, str_ends_with($path, 'read-all') ? ['confirm' => true] : [])
                ->assertStatus($expected === 200 ? (str_ends_with($path, 'read-all') ? 303 : 404) : $expected);
        }
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_another_recipient_and_unsupported_kind_cannot_be_read_even_by_admin(): void
    {
        $notice = $this->notice();
        $legacy = $this->notice($this->admin, changes: ['type' => 'fixture.legacy', 'fall_incident_id' => null]);
        $this->signIn($this->admin);
        $before = $this->snapshot();
        foreach ([$notice->id, $legacy->id, (string) Str::uuid(), 'invalid'] as $id) {
            $this->postJson('/notifications/'.$id.'/read')->assertNotFound();
        }
        $this->assertSame($before, $this->snapshot());
        $this->getJson('/notifications/summary')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('pending_count', 1);
        $this->assertSame(0, $this->get('/notifications')->assertOk()->viewData('page')['props']['records']['total']);
        $this->postJson('/monitoring/incidents/'.$this->incident->id.'/acknowledge')->assertForbidden();
    }

    public function test_real_csrf_is_required_and_valid_token_succeeds(): void
    {
        $notice = $this->notice();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/notifications/'.$notice->id.'/read')->assertStatus(419);
        $this->assertNull($notice->fresh()->read_at);
        $this->post('/notifications/'.$notice->id.'/read', ['_token' => session()->token()])->assertStatus(303);
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public static function injectedFields(): array
    {
        return [['notifiable_id'], ['notifiable_type'], ['type'], ['fall_incident_id'], ['read_at'], ['data'], ['id']];
    }

    #[DataProvider('injectedFields')]
    public function test_mutation_rejects_untrusted_fields_without_old_input(string $field): void
    {
        $notice = $this->notice();
        $before = $this->snapshot();
        $this->postJson('/notifications/'.$notice->id.'/read', [$field => 'PRIVATE-SPOOF'])->assertUnprocessable()->assertDontSee('PRIVATE-SPOOF');
        $this->post('/notifications/read-all', ['confirm' => true, $field => 'PRIVATE-SPOOF'])
            ->assertStatus(303)->assertSessionHasErrors('notification');
        $this->assertNull(session()->getOldInput($field));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_family_with_a_raw_notification_still_has_no_access_or_monitoring_links(): void
    {
        $this->notice($this->family);
        $this->signIn($this->family);
        $this->get('/notifications')->assertForbidden();
        $this->getJson('/notifications/summary')->assertForbidden();
        $this->postJson('/notifications/read-all', ['confirm' => true])->assertForbidden();
        $this->get('/monitoring/incidents/'.$this->incident->id)->assertForbidden();
    }

    public function test_session_revocation_and_stale_actor_do_not_allow_mutation(): void
    {
        $notice = $this->notice();
        Auth::guard('web')->login($this->nurse);
        $this->get('/notifications')->assertOk();
        $this->nurse->forceFill(['is_active' => false])->save();
        Auth::forgetGuards();
        $this->getJson('/notifications/summary')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_summary_rate_limit_and_error_queries_do_not_leak_input(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/notifications/summary')->assertOk();
        }
        $this->getJson('/notifications/summary')->assertStatus(429);
        $this->signIn($this->admin);
        $this->getJson('/notifications/summary')->assertOk();
        $this->get('/notifications?notes=PRIVATE-QUERY')->assertRedirect('/notifications')->assertDontSee('PRIVATE-QUERY');
        $this->post('/notifications/invalid/read', ['state' => 'all'])->assertNotFound()->assertDontSee('SQLSTATE');
    }
}
