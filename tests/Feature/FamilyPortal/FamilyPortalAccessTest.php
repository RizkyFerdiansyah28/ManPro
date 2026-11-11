<?php

namespace Tests\Feature\FamilyPortal;

use App\Services\AccountService;
use App\Services\ResidentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FamilyPortalFeatureTestCase;

class FamilyPortalAccessTest extends FamilyPortalFeatureTestCase
{
    public static function roles(): array
    {
        return [['keluarga', true, 200], ['keluarga', false, 302], ['admin', true, 403],
            ['admin', false, 302], ['perawat', true, 403], ['perawat', false, 302], [null, true, 302]];
    }

    #[DataProvider('roles')]
    public function test_all_three_routes_require_an_active_family(?string $role, bool $active, int $status): void
    {
        $actor = $role ? $this->account($role, $active) : null;
        $e = $this->resident($role === 'keluarga' ? $actor : null);
        $before = $this->snapshot();
        foreach (['/keluarga', '/keluarga/residents', '/keluarga/residents/'.$e->id] as $url) {
            $this->signIn($actor);
            $this->get($url)->assertStatus($status);
        }
        $after = $this->snapshot();
        if ($actor && ! $active) {
            $row = collect($after['users'])->firstWhere('id', $actor->id);
            $this->assertSame(60, strlen($row['remember_token']));
            $before['users'] = array_map(fn ($u) => $u['id'] === $actor->id ? [...$u, 'remember_token' => $row['remember_token']] : $u, $before['users']);
            $this->assertGuest();
        }
        $this->assertSame($before, $after);
    }

    public function test_dashboard_scopes_counts_previews_and_empty_state_and_uses_one_projection(): void
    {
        $room = $this->room();
        $one = $this->resident();
        $this->enrollment($one, $room);
        $this->stay($one, $room);
        for ($i = 0; $i < 7; $i++) {
            $this->resident(changes: ['name' => 'Nama '.$i, 'is_active' => $i !== 0]);
        }
        $foreign = $this->resident($this->account('keluarga'), ['name' => 'PRIVATE-OTHER']);
        $before = $this->snapshot();
        DB::enableQueryLog();
        $page = $this->get('/keluarga')->assertOk()->viewData('page');
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'owned AS MATERIALIZED'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertSame(['staying' => 1, 'enrolled' => 1, 'residents' => 8, 'needs_review' => 0, 'without_service' => 7, 'active_residents' => 7], $page['props']['summary']);
        $this->assertCount(6, $page['props']['residents']);
        $this->assertStringNotContainsString($foreign->name, json_encode($page));
        $this->assertSame($before, $this->snapshot());
        $this->signIn($this->account('keluarga'));
        $empty = $this->get('/keluarga')->assertOk()->viewData('page')['props'];
        $this->assertSame(0, $empty['summary']['residents']);
        $this->assertSame([], $empty['residents']);
    }

    public function test_transfer_revokes_old_urls_and_gives_current_service_to_new_family_without_history(): void
    {
        $room = $this->room();
        $e = $this->resident();
        $other = $this->resident(changes: ['name' => 'Tetap milik lama']);
        $this->enrollment($e, $room);
        $this->stay($e, $room);
        $next = $this->account('keluarga');
        $this->assertSame('123.45', $this->detail($e)['resident']['service']['enrollment']['monthly_price']);
        app(ResidentService::class)->reassignFamily($this->admin, $e, ['family_id' => $next->id,
            'current_family_id' => $this->family->id, 'confirm_reassignment' => true]);
        $this->get('/keluarga/residents/'.$e->id)->assertNotFound();
        $this->assertSame($other->id, $this->get('/keluarga/residents')->assertOk()->viewData('page')['props']['residents']['data'][0]['id']);
        $this->signIn($next);
        $props = $this->detail($e);
        $this->assertSame('123.45', $props['resident']['service']['enrollment']['monthly_price']);
        foreach (['family_id', 'family', 'invoices', 'payments', 'stays', 'enrollments', 'fall_incidents', 'gait_observations'] as $key) {
            $this->assertArrayNotHasKey($key, $props['resident']);
        }
        $this->get('/keluarga/residents/'.$other->id)->assertNotFound();
    }

    public static function invalidFilters(): array
    {
        return [['family_id', 'PRIVATE'], ['user_id', 'PRIVATE'], ['room_id', 'PRIVATE'], ['service_class_id', 'PRIVATE'],
            ['sort', 'PRIVATE'], ['notes', 'PRIVATE'], ['monthly_price', '123'], ['benefits', 'PRIVATE'],
            ['page', '10001'], ['page', '0'], ['per_page', '1000'], ['status', 'PRIVATE'], ['search', ['PRIVATE']], ['search', null]];
    }

    #[DataProvider('invalidFilters')]
    public function test_filters_reject_spoof_and_do_not_flash_or_reflect_it(string $key, mixed $value): void
    {
        $value ??= str_repeat('X', 101);
        $before = $this->snapshot();
        $query = http_build_query([$key => $value]);
        $this->getJson('/keluarga/residents?'.$query)->assertUnprocessable()->assertDontSee('PRIVATE');
        $this->get('/keluarga/residents?'.$query)->assertRedirect('/keluarga/residents')->assertSessionHasErrors();
        $this->assertSame([], session()->getOldInput());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_error_urls_are_sanitized_and_no_staff_or_mutation_path_is_opened(): void
    {
        $e = $this->resident();
        $foreign = $this->resident($this->account('keluarga'));
        $this->get('/keluarga/residents/'.$foreign->id.'?notes=PRIVATE-ERROR')
            ->assertRedirect('/keluarga/residents/'.$foreign->id)->assertDontSee('PRIVATE-ERROR');
        // Role ditolak sebelum FormRequest, tanpa memantulkan query privat.
        $this->signIn($this->admin);
        $this->get('/keluarga?notes=PRIVATE-ERROR')->assertForbidden()->assertDontSee('PRIVATE-ERROR');
        $this->signIn($this->family);
        foreach (['/monitoring/records', '/notifications', '/admin/residents/'.$e->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/keluarga/residents/'.$e->id, [])->assertStatus(405);
        $this->get('/keluarga/residents/'.$e->id.'/incidents')->assertNotFound();
        $this->get('/keluarga/residents/'.Str::uuid())->assertNotFound();
    }

    public function test_session_revocation_still_applies_to_family_portal(): void
    {
        $e = $this->resident();
        Auth::guard('web')->login($this->family);
        $this->get('/keluarga')->assertOk();
        app(AccountService::class)->resetPassword($this->admin, $this->family, 'BaruAman12345678');
        Auth::forgetGuards();
        $this->get('/keluarga/residents/'.$e->id)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_pagination_search_inactive_and_decimal_snapshot_do_not_use_master_price(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->resident(changes: ['name' => 'Nama sama']);
        }
        $special = $this->resident(changes: ['name' => 'Nama %_ literal', 'is_active' => false]);
        $room = $this->room();
        $this->enrollment($special, $room, ['monthly_price' => '0.00']);
        $room->serviceClass->update(['monthly_price' => '999.99']);
        $before = $this->snapshot();
        $page = $this->get('/keluarga/residents?per_page=15')->assertOk()->viewData('page')['props']['residents'];
        $this->assertSame(17, $page['total']);
        $this->assertCount(15, $page['data']);
        $this->assertStringNotContainsString('family_id', $page['next_page_url']);
        $next = $this->get($page['next_page_url'])->assertOk()->viewData('page')['props']['residents'];
        $this->assertCount(2, $next['data']);
        $this->assertSame([], array_intersect(array_column($page['data'], 'id'), array_column($next['data'], 'id')));
        $one = $this->get('/keluarga/residents?'.http_build_query(['search' => '%_', 'status' => 'inactive']))->assertOk()->viewData('page')['props']['residents'];
        $this->assertSame(1, $one['total']);
        $this->assertSame('0.00', $one['data'][0]['service']['enrollment']['monthly_price']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_zero_search_is_a_literal_filter_not_an_empty_search(): void
    {
        $wanted = $this->resident(changes: ['name' => 'Nomor 0']);
        $this->resident(changes: ['name' => 'Nama lain']);
        $page = $this->get('/keluarga/residents?search=0')->assertOk()->viewData('page')['props']['residents'];
        $this->assertSame(1, $page['total']);
        $this->assertSame($wanted->id, $page['data'][0]['id']);
    }

    public function test_snapshot_rechecks_a_stale_actors_database_status_instead_of_returning_zero(): void
    {
        $this->resident();
        // actingAs menyimpan actor aktif di guard; ubah DB tanpa memperbarui object actor.
        DB::table('users')->where('id', $this->family->id)->update(['is_active' => false]);
        $this->assertTrue(auth()->user()->is_active);
        $this->get('/keluarga')->assertForbidden()->assertDontSee('summary');
        $this->get('/keluarga/residents')->assertForbidden()->assertDontSee('residents.data');
    }
}
