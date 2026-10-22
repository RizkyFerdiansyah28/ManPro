<?php

namespace Tests\Feature\Facilities;

use App\Models\Room;
use App\Models\ServiceClass;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FacilityFeatureTestCase;

class FacilityAuthorizationTest extends FacilityFeatureTestCase
{
    private function reads(Room $room): array
    {
        return ['/admin/service-classes', "/admin/service-classes/{$this->shared->id}", "/admin/service-classes/{$this->shared->id}/edit",
            '/admin/rooms', '/admin/rooms/create', "/admin/rooms/{$room->id}", "/admin/rooms/{$room->id}/edit"];
    }

    private function writes(Room $room): array
    {
        return [['PATCH', "/admin/service-classes/{$this->shared->id}", ['name' => 'Valid', 'monthly_price' => '100.00']],
            ['POST', "/admin/service-classes/{$this->shared->id}/activate", ['confirm_status_change' => true]],
            ['POST', "/admin/service-classes/{$this->basic->id}/deactivate", ['confirm_status_change' => true]],
            ['POST', '/admin/rooms', $this->roomPayload(changes: ['code' => 'NEW'])],
            ['PATCH', "/admin/rooms/{$room->id}", $this->roomUpdate($room, ['name' => 'Valid'])],
            ['POST', "/admin/rooms/{$room->id}/activate", ['confirm_status_change' => true]],
            ['POST', "/admin/rooms/{$room->id}/deactivate", ['confirm_status_change' => true]]];
    }

    public static function nonAdminRoles(): array
    {
        return [['keluarga'], ['perawat']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_every_read_and_mutation_is_admin_only_even_for_known_related_ids(string $role): void
    {
        $room = $this->room();
        $stay = $this->stay($room);
        $actor = $role === 'keluarga' ? $stay->elderly->family : $this->account($role);
        $before = $this->historySnapshot();
        $attributes = $room->getAttributes();
        $class = $this->shared->getAttributes();
        $this->app['session']->driver()->flush();
        $this->actingAs($actor);
        foreach ($this->reads($room) as $path) {
            $this->get($path)->assertForbidden();
        }
        foreach ($this->writes($room) as [$method, $path, $payload]) {
            $this->call($method, $path, $payload)->assertForbidden();
        }
        $this->getJson('/admin/rooms')->assertForbidden();
        $this->getJson('/admin/service-classes')->assertForbidden();
        foreach ([['viewAny', Room::class], ['create', Room::class], ['view', $room], ['update', $room], ['updateStatus', $room],
            ['viewAny', ServiceClass::class], ['view', $this->shared], ['update', $this->shared], ['updateStatus', $this->shared]] as [$ability, $target]) {
            $this->assertFalse(Gate::forUser($actor)->allows($ability, $target));
        }
        $this->assertSame($before, $this->historySnapshot());
        $this->assertSame($attributes, $room->fresh()->getAttributes());
        $this->assertSame($class, $this->shared->fresh()->getAttributes());
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_guests_cannot_read_or_mutate_facilities(): void
    {
        $room = $this->room();
        Auth::logout();
        $this->app['session']->driver()->flush();
        foreach ($this->reads($room) as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach ($this->writes($room) as [$method, $path, $payload]) {
            $this->call($method, $path, $payload)->assertRedirect('/login');
        }
        $this->getJson('/admin/rooms')->assertUnauthorized();
        $this->postJson('/admin/rooms', $this->roomPayload(changes: ['code' => 'NEW']))->assertUnauthorized();
        $this->assertDatabaseCount('rooms', 1);
        $this->assertSame('Kamar Uji', $room->fresh()->name);
    }

    public static function roles(): array
    {
        return [['admin'], ['perawat'], ['keluarga']];
    }

    #[DataProvider('roles')]
    public function test_inactive_existing_sessions_cannot_read_or_mutate_any_facility(string $role): void
    {
        $room = $this->room();
        $actor = $role === 'admin' ? $this->admin : $this->account($role);
        Auth::logout();
        $this->app['session']->driver()->flush();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $actor->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->get('/'.$role)->assertOk();
        $authenticatedSession = session()->all();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        foreach ([...array_map(fn ($path) => ['GET', $path, []], $this->reads($room)), ...$this->writes($room)] as [$method, $path, $payload]) {
            $this->app['session']->driver()->flush();
            // Putar ulang sesi yang sebelumnya sah; request baru memuat status dari provider DB.
            $this->withSession($authenticatedSession);
            Auth::forgetGuards();
            $this->call($method, $path, $payload)->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('rooms', 1);
        $this->assertSame('Kamar Uji', $room->fresh()->name);
        $this->assertTrue($room->fresh()->is_active);
    }

    public function test_facility_mutations_require_csrf_and_valid_token_can_create_room(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $room = $this->room();
        $before = $room->getAttributes();
        $class = $this->shared->getAttributes();
        $this->get('/admin/rooms')->assertOk();
        foreach ($this->writes($room) as [$method, $path, $payload]) {
            $this->call($method, $path, $payload)->assertStatus(419);
        }
        $this->assertSame($before, $room->fresh()->getAttributes());
        $this->assertSame($class, $this->shared->fresh()->getAttributes());
        $this->post('/admin/rooms', [...$this->roomPayload(changes: ['code' => 'TOKEN-OK']), '_token' => csrf_token()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('rooms', ['code' => 'TOKEN-OK']);
    }

    public function test_missing_and_malformed_resource_ids_return_404(): void
    {
        foreach (['invalid-uuid', (string) Str::uuid()] as $id) {
            foreach (['rooms', 'service-classes'] as $resource) {
                $this->get("/admin/{$resource}/{$id}")->assertNotFound();
                $this->get("/admin/{$resource}/{$id}/edit")->assertNotFound();
                $this->patch("/admin/{$resource}/{$id}", [])->assertNotFound();
                $this->post("/admin/{$resource}/{$id}/deactivate", ['confirm_status_change' => true])->assertNotFound();
            }
        }
    }
}
