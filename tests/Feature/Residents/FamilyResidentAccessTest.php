<?php

namespace Tests\Feature\Residents;

use App\Models\Elderly;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresFeatureTestCase;

class FamilyResidentAccessTest extends PostgresFeatureTestCase
{
    private function resident(User $family, array $attributes = []): Elderly
    {
        return Elderly::create(['family_id' => $family->id, 'name' => 'Lansia Milik Keluarga', 'birth_date' => '1950-01-01', ...$attributes]);
    }

    private function useAccount(User $user): void
    {
        // Tiap actor mewakili sesi browser berbeda, bukan mengganti user pada sesi yang sama.
        $this->app['session']->driver()->flush();
        $this->actingAs($user);
    }

    public function test_family_list_is_scoped_paginated_searchable_and_has_no_unrelated_relations(): void
    {
        $family = $this->account('keluarga');
        $foreign = $this->resident($this->account('keluarga'), ['name' => 'Rahasia Keluarga Lain']);
        for ($i = 0; $i < 16; $i++) {
            $this->resident($family, ['name' => "Keluarga Saya {$i}"]);
        }
        $inactive = $this->resident($family, ['name' => 'Lansia Nonaktif', 'is_active' => false]);
        $this->useAccount($family);
        $this->get('/keluarga/residents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Family/Residents/Index')->has('residents.data', 15)->where('residents.total', 17)
            ->missing('residents.data.0.family')->missing('residents.data.0.family_id')->missing('residents.data.0.invoices'));
        $this->get('/keluarga/residents?page=2')->assertInertia(fn (Assert $page) => $page->has('residents.data', 2));
        $this->get('/keluarga/residents?search=lansia&status=inactive')->assertInertia(fn (Assert $page) => $page
            ->has('residents.data', 1)->where('residents.data.0.id', $inactive->id));
        $this->get('/keluarga/residents?search=Rahasia')->assertInertia(fn (Assert $page) => $page->has('residents.data', 0));
        $this->get('/keluarga/residents?'.http_build_query(['family_id' => $foreign->family_id]))->assertSessionHasErrors('family_id');
    }

    public function test_own_detail_is_read_only_and_foreign_ids_are_indistinguishable_from_missing(): void
    {
        $family = $this->account('keluarga');
        $own = $this->resident($family);
        $foreign = $this->resident($this->account('keluarga'), ['name' => 'Nama Rahasia Lain']);
        $this->useAccount($family);
        $this->get("/keluarga/residents/{$own->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Family/Residents/Show')->where('resident.id', $own->id)
            ->missing('resident.family')->missing('resident.family_id')->missing('resident.invoices')
            ->missing('resident.stays')->missing('resident.gait_observations')->missing('resident.fall_incidents'));
        foreach ([$foreign->id, (string) Str::uuid(), 'invalid-uuid'] as $id) {
            $this->get("/keluarga/residents/{$id}")->assertNotFound()->assertDontSee($foreign->name)
                ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404)->missing('resident'));
        }
        $this->assertTrue(Gate::forUser($family)->allows('view', $own));
        $this->assertFalse(Gate::forUser($family)->allows('view', $foreign));
        $this->assertFalse(Gate::forUser($family)->allows('update', $own));
    }

    public function test_foreign_detail_query_itself_contains_family_scope(): void
    {
        $family = $this->account('keluarga');
        $foreign = $this->resident($this->account('keluarga'));
        $this->useAccount($family);
        DB::enableQueryLog();
        $this->get("/keluarga/residents/{$foreign->id}")->assertNotFound();
        $queries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from "elderlies"'));
        $this->assertNotEmpty($queries);
        foreach ($queries as $entry) {
            $this->assertStringContainsString('"family_id" = ?', $entry['query']);
            $this->assertContains($family->id, $entry['bindings']);
        }
        DB::disableQueryLog();
    }

    public function test_family_with_no_residents_receives_empty_page(): void
    {
        $this->resident($this->account('keluarga'));
        $this->useAccount($this->account('keluarga'));
        $this->get('/keluarga/residents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Family/Residents/Index')->has('residents.data', 0)->where('residents.total', 0));
    }

    public static function nonAdminRoles(): array
    {
        return [['keluarga'], ['perawat']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_use_admin_reads_selector_or_writes(string $role): void
    {
        $family = $this->account('keluarga');
        $elderly = $this->resident($family)->fresh();
        $before = $elderly->getAttributes();
        $actor = $role === 'keluarga' ? $family : $this->account($role);
        $next = $this->account('keluarga');
        $this->useAccount($actor);
        $root = "/admin/residents/{$elderly->id}";
        foreach (['/admin/residents', '/admin/residents/create', $root, "{$root}/edit"] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->getJson('/admin/family-options')->assertForbidden();
        $this->post('/admin/residents', ['name' => 'Nama Valid', 'birth_date' => '1950-01-01', 'family_id' => $family->id])->assertForbidden();
        $this->patch($root, ['name' => 'Nama Valid', 'birth_date' => '1950-01-01'])->assertForbidden();
        $this->post("{$root}/family", ['family_id' => $next->id, 'current_family_id' => $family->id, 'confirm_reassignment' => true])->assertForbidden();
        foreach (['activate', 'deactivate'] as $action) {
            $this->post("{$root}/{$action}", ['confirm_status_change' => true])->assertForbidden();
        }
        $this->assertSame($before, $elderly->fresh()->getAttributes());
        $this->assertDatabaseCount('elderlies', 1);
        $this->assertFalse(Gate::forUser($actor)->allows('updateStatus', $elderly));
        $this->assertFalse(Gate::forUser($actor)->allows('reassignFamily', $elderly));
    }

    public function test_caregiver_and_admin_do_not_gain_access_to_family_routes(): void
    {
        $elderly = $this->resident($this->account('keluarga'));
        foreach (['admin', 'perawat'] as $role) {
            $this->useAccount($this->account($role));
            $this->get('/keluarga/residents')->assertForbidden();
            $this->get("/keluarga/residents/{$elderly->id}")->assertForbidden();
        }
        $this->get('/perawat')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Caregiver/Monitoring/Dashboard'));
    }

    public function test_reassignment_transfers_read_access_without_changing_resident_identity(): void
    {
        $old = $this->account('keluarga');
        $next = $this->account('keluarga');
        $elderly = $this->resident($old);
        $this->useAccount($this->account());
        $this->post("/admin/residents/{$elderly->id}/family", ['family_id' => $next->id,
            'current_family_id' => $old->id, 'confirm_reassignment' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->useAccount($old);
        $this->get('/keluarga/residents')->assertInertia(fn (Assert $page) => $page->has('residents.data', 0));
        $this->get("/keluarga/residents/{$elderly->id}")->assertNotFound();
        $this->useAccount($next);
        $this->get("/keluarga/residents/{$elderly->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resident.id', $elderly->id)->missing('resident.family'));
        $this->assertDatabaseHas('elderlies', ['id' => $elderly->id, 'family_id' => $next->id]);
    }

    public function test_deactivated_family_loses_existing_session_but_relationship_is_preserved(): void
    {
        $admin = $this->account();
        $family = $this->account('keluarga');
        $elderly = $this->resident($family);
        $this->post('/login', ['email' => $family->email, 'password' => self::PASSWORD])->assertRedirect('/keluarga');
        $this->get('/keluarga/residents')->assertOk();
        app(AccountService::class)->setActive($admin, $family, false);
        Auth::forgetGuards();
        $this->get("/keluarga/residents/{$elderly->id}")->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('elderlies', ['id' => $elderly->id, 'family_id' => $family->id]);
    }

    public static function roles(): array
    {
        return [['admin', '/admin/residents'], ['perawat', '/admin/residents'], ['keluarga', '/keluarga/residents']];
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_cannot_access_resident_routes(string $role, string $path): void
    {
        $this->useAccount($this->account($role, false));
        $this->get($path)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_guest_requests_cannot_read_or_mutate_residents(): void
    {
        $family = $this->account('keluarga');
        $elderly = $this->resident($family)->fresh();
        $before = $elderly->getAttributes();
        foreach (['/admin/residents', '/admin/residents/create', "/admin/residents/{$elderly->id}",
            "/admin/residents/{$elderly->id}/edit", '/keluarga/residents', "/keluarga/residents/{$elderly->id}", '/admin/family-options'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->getJson('/admin/family-options')->assertUnauthorized();
        $this->post('/admin/residents', ['name' => 'Valid', 'birth_date' => '1950-01-01', 'family_id' => $family->id])->assertRedirect('/login');
        $this->patch("/admin/residents/{$elderly->id}", ['name' => 'Valid', 'birth_date' => '1950-01-01'])->assertRedirect('/login');
        foreach (['family', 'activate', 'deactivate'] as $action) {
            $this->post("/admin/residents/{$elderly->id}/{$action}", [])->assertRedirect('/login');
        }
        $this->assertSame($before, $elderly->fresh()->getAttributes());
    }

    public function test_resident_mutations_require_csrf_protection(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $family = $this->account('keluarga');
        $elderly = $this->resident($family)->fresh();
        $before = $elderly->getAttributes();
        $this->useAccount($this->account());
        $this->get('/admin/residents')->assertOk();
        $this->post('/admin/residents', ['name' => 'Valid', 'birth_date' => '1950-01-01', 'family_id' => $family->id])->assertStatus(419);
        $this->patch("/admin/residents/{$elderly->id}", ['name' => 'Valid', 'birth_date' => '1950-01-01'])->assertStatus(419);
        foreach (['family', 'activate', 'deactivate'] as $action) {
            $this->post("/admin/residents/{$elderly->id}/{$action}", ['confirm_status_change' => true])->assertStatus(419);
        }
        $this->assertSame($before, $elderly->fresh()->getAttributes());
        $this->assertDatabaseCount('elderlies', 1);
    }
}
