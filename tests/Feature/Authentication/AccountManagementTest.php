<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use App\Services\AccountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresFeatureTestCase;

class AccountManagementTest extends PostgresFeatureTestCase
{
    public static function managedRoles(): array
    {
        return [['perawat'], ['keluarga']];
    }

    #[DataProvider('managedRoles')]
    public function test_admin_provisions_inactive_uuid_accounts_with_hashed_passwords(string $role): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->get('/admin/accounts/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Accounts/Create'));
        $this->post("/admin/accounts/{$role}", $this->accountPayload(['email' => '  BARU@example.test  ']))
            ->assertSessionHasNoErrors()->assertRedirect('/admin/accounts');
        $created = User::where('email', 'baru@example.test')->sole();
        $this->assertTrue(Str::isUuid($created->id));
        $this->assertSame($role, $created->role);
        $this->assertFalse($created->is_active);
        $this->assertNotSame(self::PASSWORD, $created->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('managedRoles')]
    public function test_non_admin_cannot_read_or_mutate_accounts_even_with_known_ids(string $role): void
    {
        $actor = $this->account($role);
        $target = $this->account('keluarga');
        $before = $target->fresh()->getAttributes();
        $this->actingAs($actor);
        foreach (['/admin', '/admin/accounts', '/admin/accounts/create', "/admin/accounts/{$target->id}/password"] as $path) {
            $this->get($path)->assertForbidden();
        }
        foreach (['/admin/accounts/perawat', '/admin/accounts/keluarga',
            "/admin/accounts/{$target->id}/activate", "/admin/accounts/{$target->id}/deactivate",
            "/admin/accounts/{$target->id}/password"] as $path) {
            $this->post($path, [...$this->accountPayload(), 'current_password' => self::PASSWORD])->assertForbidden();
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
    }

    public static function protectedPayloads(): array
    {
        return ['admin role' => ['role', 'admin'], 'empty role' => ['role', ''],
            'active' => ['is_active', true], 'null status' => ['is_active', null],
            'id override' => ['id', '00000000-0000-4000-8000-000000000000'],
            'remember token' => ['remember_token', 'forged'], 'verified email' => ['email_verified_at', '2026-10-21']];
    }

    #[DataProvider('protectedPayloads')]
    public function test_protected_creation_fields_are_rejected_even_when_empty(string $field, mixed $value): void
    {
        $this->actingAs($this->account())->post('/admin/accounts/keluarga', $this->accountPayload([$field => $value]))
            ->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_role_and_status_are_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill(['name' => 'Nama', 'email' => 'nama@example.test', 'password' => self::PASSWORD,
            'role' => 'admin', 'is_active' => true]);
        $this->assertArrayNotHasKey('role', $user->getAttributes());
        $this->assertArrayNotHasKey('is_active', $user->getAttributes());
    }

    public static function invalidPasswords(): array
    {
        return ['short' => ['Abc12', 'Abc12'], 'no uppercase' => ['hurufkecil12345', 'hurufkecil12345'],
            'no number' => ['TanpaAngkaDisini', 'TanpaAngkaDisini'], 'confirmation' => [self::PASSWORD, 'Berbeda123456'],
            'over byte limit' => ['Aa1'.str_repeat('é', 36), 'Aa1'.str_repeat('é', 36)],
            'null byte' => ["Password\0Aman123", "Password\0Aman123"]];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_does_not_create_account_or_flash_secret(string $password, string $confirmation): void
    {
        $this->actingAs($this->account())->post('/admin/accounts/perawat', $this->accountPayload([
            'password' => $password, 'password_confirmation' => $confirmation,
        ]))->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_uniqueness_is_case_insensitive_and_filters_are_validated(): void
    {
        $this->account('keluarga', true, ['email' => 'Family@Example.test']);
        $this->actingAs($this->account())->post('/admin/accounts/keluarga', $this->accountPayload(['email' => 'family@example.test']))
            ->assertSessionHasErrors('email');
        $this->get('/admin/accounts?role=superuser&status=unknown&page=0')->assertSessionHasErrors(['role', 'status', 'page']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_list_is_paginated_searchable_filtered_and_serialization_is_restricted(): void
    {
        $admin = $this->account();
        User::factory()->count(16)->create(['role' => 'perawat', 'is_active' => true]);
        $target = $this->account('keluarga', false, ['name' => 'Nama Unik Pencarian', 'email' => 'dicari@example.test']);
        $this->actingAs($admin)->get('/admin/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Accounts/Index')->has('accounts.data', 15)->where('accounts.total', 18)
            ->missing('accounts.data.0.password')->missing('accounts.data.0.remember_token')
            ->missing('auth.user.password')->missing('auth.user.email_verified_at'));
        $this->get('/admin/accounts?page=2')->assertInertia(fn (Assert $page) => $page->has('accounts.data', 3));
        foreach (['nama UNIK', 'DICARI@EXAMPLE.TEST'] as $search) {
            $this->get('/admin/accounts?'.http_build_query(['search' => $search, 'role' => 'keluarga', 'status' => 'inactive']))
                ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1)
                    ->where('accounts.data.0.id', $target->id)->where('accounts.data.0.can.updateStatus', true));
        }
        $this->get('/admin/accounts?search=%25')->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
        $this->get('/admin/accounts?search=0&role=keluarga&status=inactive')
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
        $this->get('/admin/accounts?role=admin')->assertInertia(fn (Assert $page) => $page
            ->has('accounts.data', 1)->where('accounts.data.0.can.updateStatus', false)->where('accounts.data.0.can.resetPassword', false));
    }

    public function test_admin_can_activate_deactivate_without_changing_role_and_cannot_inject_status(): void
    {
        $target = $this->account('perawat', false);
        $this->actingAs($this->account());
        $this->post("/admin/accounts/{$target->id}/activate", ['role' => 'admin', 'is_active' => true])
            ->assertSessionHasErrors(['role', 'is_active']);
        $this->assertFalse($target->fresh()->is_active);
        $this->post("/admin/accounts/{$target->id}/activate")->assertRedirect('/admin/accounts');
        $this->assertTrue($target->fresh()->is_active);
        $this->post("/admin/accounts/{$target->id}/deactivate")->assertRedirect('/admin/accounts');
        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame('perawat', $target->fresh()->role);
    }

    public function test_admin_cannot_deactivate_or_reset_self_or_another_admin(): void
    {
        $admin = $this->account();
        $other = $this->account();
        $this->actingAs($admin);
        foreach ([$admin, $other] as $target) {
            $before = $target->fresh()->getAttributes();
            $this->post("/admin/accounts/{$target->id}/deactivate")->assertForbidden();
            $this->post("/admin/accounts/{$target->id}/activate")->assertForbidden();
            $this->get("/admin/accounts/{$target->id}/password")->assertForbidden();
            $this->post("/admin/accounts/{$target->id}/password", [...$this->accountPayload(), 'current_password' => self::PASSWORD])->assertForbidden();
            $this->assertSame($before, $target->fresh()->getAttributes());
        }
    }

    public function test_inactive_admin_cannot_provision_or_change_other_accounts(): void
    {
        $inactive = $this->account('admin', false);
        $target = $this->account('keluarga');
        $this->actingAs($inactive)->post("/admin/accounts/{$target->id}/deactivate")->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue($target->fresh()->is_active);
        $this->post('/admin/accounts/perawat', $this->accountPayload())->assertRedirect('/login');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_service_rechecks_fresh_admin_authority_before_mutation(): void
    {
        $admin = $this->account();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        try {
            app(AccountService::class)->create($admin, $this->accountPayload(), 'keluarga');
        } finally {
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_invalid_and_missing_uuid_targets_are_rejected_without_mutation(): void
    {
        $this->actingAs($this->account());
        foreach (['not-a-uuid', (string) Str::uuid()] as $id) {
            $this->post("/admin/accounts/{$id}/deactivate")->assertNotFound();
            $this->get("/admin/accounts/{$id}/password")->assertNotFound();
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_reset_requires_admin_password_and_revokes_only_target_credentials_and_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->account();
        $target = $this->account('keluarga', false);
        $other = $this->account('perawat');
        $before = $target->fresh()->getAttributes();
        foreach ([$admin, $target, $other] as $user) {
            DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id,
                'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        DB::table('password_reset_tokens')->insert(['email' => $target->email, 'token' => 'fixture-token']);
        $this->actingAs($admin)->get("/admin/accounts/{$target->id}/password")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Accounts/ResetPassword')
                ->where('account.id', $target->id)->missing('account.password'));
        $payload = ['password' => 'KataSandiPengganti456', 'password_confirmation' => 'KataSandiPengganti456'];
        $this->post("/admin/accounts/{$target->id}/password", [...$payload, 'current_password' => 'Salah12345678'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseHas('sessions', ['id' => 'session-'.$target->id]);
        $this->post("/admin/accounts/{$target->id}/password", [...$payload, 'current_password' => self::PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/accounts');
        $updated = $target->fresh();
        $this->assertTrue(Hash::check($payload['password'], $updated->password));
        $this->assertFalse(Hash::check(self::PASSWORD, $updated->password));
        $this->assertNotSame($before['remember_token'], $updated->remember_token);
        $this->assertSame('keluarga', $updated->role);
        $this->assertFalse($updated->is_active);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        foreach ([$admin, $other] as $preserved) {
            $this->assertDatabaseHas('sessions', ['id' => 'session-'.$preserved->id, 'user_id' => $preserved->id]);
        }
    }

    public function test_deactivation_removes_database_sessions_without_deleting_the_account(): void
    {
        config(['session.driver' => 'database']);
        $target = $this->account('perawat');
        DB::table('sessions')->insert(['id' => 'revoke-target', 'user_id' => $target->id, 'payload' => 'fixture', 'last_activity' => time()]);
        $this->actingAs($this->account())->post("/admin/accounts/{$target->id}/deactivate")->assertRedirect('/admin/accounts');
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'perawat', 'is_active' => false]);
    }

    public function test_password_reset_rejects_privilege_payload_even_with_valid_admin_password(): void
    {
        $target = $this->account('keluarga');
        $before = $target->fresh()->getAttributes();
        $this->actingAs($this->account())->post("/admin/accounts/{$target->id}/password", [
            'current_password' => self::PASSWORD, 'password' => 'KataSandiPengganti456',
            'password_confirmation' => 'KataSandiPengganti456', 'role' => 'admin', 'is_active' => false,
        ])->assertSessionHasErrors(['role', 'is_active']);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_password_reset_is_rate_limited_per_admin(): void
    {
        $target = $this->account('keluarga');
        $this->actingAs($this->account());
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post("/admin/accounts/{$target->id}/password", ['current_password' => 'WrongPassword123',
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertSessionHasErrors('current_password');
        }
        $this->post("/admin/accounts/{$target->id}/password", ['current_password' => self::PASSWORD,
            'password' => 'PenggantiBenar123', 'password_confirmation' => 'PenggantiBenar123'])->assertStatus(429);
        $this->assertTrue(Hash::check(self::PASSWORD, $target->fresh()->password));
    }
}
