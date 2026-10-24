<?php

namespace Tests\Feature\Operations;

use App\Models\ElderlyStay;
use App\Models\ServiceEnrollment;
use App\Services\ResidentOperationsService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FacilityFeatureTestCase;

class OperationsAuthorizationTest extends FacilityFeatureTestCase
{
    private function fixture(): array
    {
        $elderly = $this->resident();
        $this->shared->update(['monthly_price' => '123.45']);
        $room = $this->room();
        $enrollment = $elderly->serviceEnrollments()->create(['service_class_id' => $this->shared->id, 'monthly_price' => '123.45', 'started_at' => now()->subDay()]);
        $stay = $elderly->stays()->create(['room_id' => $room->id, 'started_at' => now()->subHour()]);
        $root = "/admin/residents/{$elderly->id}";

        return [$elderly, $room, $enrollment, $stay, $root];
    }

    private function reads(string $root): array
    {
        return ["{$root}/operations", "{$root}/enrollments/create", "{$root}/stays/create"];
    }

    private function writes(string $root, $room, $enrollment, $stay): array
    {
        return [["{$root}/enrollments", ['service_class_id' => $this->shared->id, 'confirm_start' => true]],
            ["{$root}/stays", ['room_id' => $room->id, 'enrollment_id' => $enrollment->id, 'confirm_start' => true]],
            ["{$root}/stays/{$stay->id}/close", ['confirm_end' => true]], ["{$root}/enrollments/{$enrollment->id}/close", ['confirm_end' => true]]];
    }

    public static function nonAdmins(): array
    {
        return [['perawat'], ['keluarga']];
    }

    #[DataProvider('nonAdmins')]
    public function test_non_admin_cannot_read_or_mutate_even_related_operations(string $role): void
    {
        [$elderly, $room, $enrollment, $stay, $root] = $this->fixture();
        $actor = $role === 'keluarga' ? $elderly->family : $this->account($role);
        $before = $this->historySnapshot();
        $this->app['session']->driver()->flush();
        $this->actingAs($actor);
        foreach ($this->reads($root) as $path) {
            $this->get($path)->assertForbidden();
        }
        foreach ($this->writes($root, $room, $enrollment, $stay) as [$path, $payload]) {
            $this->post($path, $payload)->assertForbidden();
        }
        $this->assertFalse(Gate::forUser($actor)->allows('create', [ElderlyStay::class, $elderly]));
        $this->assertFalse(Gate::forUser($actor)->allows('create', [ServiceEnrollment::class, $elderly]));
        $this->assertFalse(Gate::forUser($actor)->allows('close', [$stay, $elderly]));
        $this->assertFalse(Gate::forUser($actor)->allows('close', [$enrollment, $elderly]));
        $this->assertSame($before, $this->historySnapshot());
        if ($role === 'keluarga') {
            $this->get("/keluarga/residents/{$elderly->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->missing('operations')->missing('resident.stays')->missing('resident.service_enrollments'));
        }
    }

    public static function roles(): array
    {
        return [['admin'], ['perawat'], ['keluarga']];
    }

    #[DataProvider('roles')]
    public function test_existing_sessions_of_inactive_roles_are_rejected(string $role): void
    {
        [$elderly, $room, $enrollment, $stay, $root] = $this->fixture();
        $actor = $role === 'admin' ? $this->admin : $this->account($role);
        Auth::logout();
        $this->app['session']->driver()->flush();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $actor->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->get('/'.$role)->assertOk();
        $session = session()->all();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $before = $this->historySnapshot();
        foreach ([...array_map(fn ($path) => ['GET', $path, []], $this->reads($root)),
            ...array_map(fn ($entry) => ['POST', ...$entry], $this->writes($root, $room, $enrollment, $stay))] as [$method, $path, $payload]) {
            $this->app['session']->driver()->flush();
            $this->withSession($session);
            Auth::forgetGuards();
            $this->call($method, $path, $payload)->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_guests_and_wrong_nested_record_ids_are_rejected_without_writes(): void
    {
        [$elderly, $room, $enrollment, $stay, $root] = $this->fixture();
        $other = $this->resident();
        $before = $this->historySnapshot();
        $this->post("/admin/residents/{$other->id}/stays/{$stay->id}/close", ['confirm_end' => true])->assertNotFound();
        $this->post("/admin/residents/{$other->id}/enrollments/{$enrollment->id}/close", ['confirm_end' => true])->assertNotFound();
        foreach (['invalid', (string) Str::uuid()] as $id) {
            $this->get("/admin/residents/{$id}/operations")->assertNotFound();
        }
        Auth::logout();
        $this->app['session']->driver()->flush();
        foreach ($this->reads($root) as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach ($this->writes($root, $room, $enrollment, $stay) as [$path, $payload]) {
            $this->post($path, $payload)->assertRedirect('/login');
        }
        $this->getJson("{$root}/operations")->assertUnauthorized();
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_mutations_require_real_csrf_token_and_valid_token_closes_a_stay(): void
    {
        [$elderly, $room, $enrollment, $stay, $root] = $this->fixture();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $before = $this->historySnapshot();
        $this->get("{$root}/operations")->assertOk();
        foreach ($this->writes($root, $room, $enrollment, $stay) as [$path, $payload]) {
            $this->post($path, $payload)->assertStatus(419);
        }
        $this->assertSame($before, $this->historySnapshot());
        $this->post("{$root}/stays/{$stay->id}/close", ['confirm_end' => true, '_token' => csrf_token()])->assertSessionHasNoErrors();
        $this->assertNotNull($stay->fresh()->ended_at);
        $this->assertNull($enrollment->fresh()->ended_at);
    }

    public function test_closure_rejects_injected_end_time_and_missing_confirmation(): void
    {
        [$elderly, $room, $enrollment, $stay, $root] = $this->fixture();
        $before = $this->historySnapshot();
        foreach (["{$root}/stays/{$stay->id}/close", "{$root}/enrollments/{$enrollment->id}/close"] as $path) {
            $this->post($path, [])->assertSessionHasErrors('confirm_end');
            $this->post($path, ['confirm_end' => true, 'ended_at' => '2026-01-01'])->assertSessionHasErrors('ended_at');
        }
        $this->assertSame($before, $this->historySnapshot());
    }

    public function test_service_rechecks_inactive_actor_and_parent_ownership(): void
    {
        [$elderly, $room, $enrollment, $stay] = $this->fixture();
        $before = $this->historySnapshot();
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
        try {
            app(ResidentOperationsService::class)->endStay($this->admin, $elderly, $stay);
            $this->fail('Actor usang yang nonaktif harus ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->historySnapshot());
    }
}
