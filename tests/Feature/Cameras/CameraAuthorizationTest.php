<?php

namespace Tests\Feature\Cameras;

use App\Models\Camera;
use App\Services\CameraService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CameraFeatureTestCase;

class CameraAuthorizationTest extends CameraFeatureTestCase
{
    private function reads(Camera $camera): array
    {
        return ['/admin/cameras', '/admin/cameras/create', "/admin/cameras/{$camera->id}", "/admin/cameras/{$camera->id}/edit"];
    }

    private function writes(Camera $camera, $free): array
    {
        return [['POST', '/admin/cameras', $this->cameraPayload($free)], ['PATCH', "/admin/cameras/{$camera->id}", $this->cameraUpdate($camera)],
            ['POST', "/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera)], ['POST', "/admin/cameras/{$camera->id}/deactivate", $this->statusPayload($camera)]];
    }

    public static function roles(): array
    {
        return [['perawat'], ['keluarga']];
    }

    #[DataProvider('roles')]
    public function test_non_admin_cannot_read_or_mutate_configuration_even_with_known_room(string $role): void
    {
        $camera = $this->camera(changes: ['source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        $free = $this->room($this->shared, ['code' => 'FREE']);
        $before = $this->historySnapshot();
        $this->app['session']->driver()->flush();
        $this->actingAs($this->account($role));
        foreach ($this->reads($camera) as $path) {
            $this->get($path)->assertForbidden()->assertDontSee('fixture-secret');
        }
        $this->getJson('/admin/camera-room-options')->assertForbidden();
        foreach ($this->writes($camera, $free) as [$method, $path, $payload]) {
            $this->call($method, $path, $payload)->assertForbidden();
        }
        foreach ([['viewAny', Camera::class], ['view', $camera], ['create', Camera::class], ['update', $camera], ['updateStatus', $camera]] as [$ability, $target]) {
            $this->assertFalse(Gate::allows($ability, $target));
        }
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_guests_and_invalid_uuids_are_rejected(): void
    {
        $camera = $this->camera();
        $free = $this->room($this->shared, ['code' => 'FREE']);
        foreach (['invalid', (string) Str::uuid()] as $id) {
            $this->get("/admin/cameras/{$id}")->assertNotFound();
        }
        Auth::logout();
        $this->app['session']->driver()->flush();
        foreach ($this->reads($camera) as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach ($this->writes($camera, $free) as [$method, $path, $payload]) {
            $this->call($method, $path, $payload)->assertRedirect('/login');
        }
        $this->getJson('/admin/camera-room-options')->assertUnauthorized();
        $this->postJson('/admin/cameras', $this->cameraPayload($free))->assertUnauthorized();
        $this->assertDatabaseCount('cameras', 1);
    }

    public function test_inactive_admin_existing_session_cannot_access_any_camera_action(): void
    {
        $camera = $this->camera();
        $free = $this->room($this->shared, ['code' => 'FREE']);
        Auth::logout();
        $this->app['session']->driver()->flush();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $session = session()->all();
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
        $before = $this->historySnapshot();
        foreach ([...array_map(fn ($path) => ['GET', $path, []], $this->reads($camera)), ...$this->writes($camera, $free)] as [$method, $path, $payload]) {
            $this->app['session']->driver()->flush();
            $this->withSession($session);
            Auth::forgetGuards();
            $this->call($method, $path, $payload)->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_real_csrf_protects_all_mutations_with_valid_token_positive_control(): void
    {
        $camera = $this->camera();
        $free = $this->room($this->shared, ['code' => 'FREE']);
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $before = $this->historySnapshot();
        $this->get('/admin/cameras')->assertOk();
        foreach ($this->writes($camera, $free) as [$method, $path, $payload]) {
            $this->call($method, $path, $payload)->assertStatus(419);
        }
        $this->assertSame($before, $this->historySnapshot());
        $this->post('/admin/cameras', [...$this->cameraPayload($free), '_token' => csrf_token()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cameras', 2);
    }

    public function test_service_reloads_actor_status_before_camera_changes(): void
    {
        $camera = $this->camera();
        $before = $camera->getAttributes();
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
        try {
            app(CameraService::class)->update($this->admin, $camera, $this->cameraUpdate($camera, ['name' => 'Dilarang']));
            $this->fail('Actor nonaktif harus ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $camera->fresh()->getAttributes());
    }
}
