<?php

namespace Tests\Feature\Authentication;

use App\Services\AccountService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresFeatureTestCase;

class SessionAuthenticationTest extends PostgresFeatureTestCase
{
    public static function roles(): array
    {
        return [['admin', '/admin'], ['perawat', '/perawat'], ['keluarga', '/keluarga']];
    }

    #[DataProvider('roles')]
    public function test_active_users_login_with_rotated_session_and_server_selected_destination(string $role, string $destination): void
    {
        $user = $this->account($role);
        $this->get('/login')->assertOk();
        $before = session()->getId();
        $token = session()->token();
        $this->withSession(['url.intended' => 'https://untrusted.example.test', 'guest_marker' => 'preserved'])
            ->post('/login', ['email' => '  '.strtoupper($user->email).'  ', 'password' => self::PASSWORD,
                'redirect' => 'https://untrusted.example.test', 'role' => 'admin', 'is_active' => true])
            ->assertSessionHasNoErrors()->assertRedirect($destination);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->get($destination)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component(match ($role) {
                'admin' => 'Admin/Monitoring/Dashboard', 'perawat' => 'Caregiver/Monitoring/Dashboard', default => 'Dashboard',
            })->where('auth.user.id', $user->id)
            ->missing('auth.user.password')->missing('auth.user.remember_token'));
        $this->get('/login')->assertRedirect($destination);
        $this->get('/dashboard')->assertRedirect($destination);
        $this->get('/')->assertRedirect($destination);
        foreach (array_diff(['/admin', '/perawat', '/keluarga'], [$destination]) as $other) {
            $this->get($other)->assertForbidden();
        }
    }

    public static function rejectedCredentials(): array
    {
        return ['inactive' => [false, self::PASSWORD], 'wrong password' => [true, 'TidakBenar12345']];
    }

    #[DataProvider('rejectedCredentials')]
    public function test_inactive_accounts_and_bad_passwords_receive_same_generic_failure(bool $active, string $password): void
    {
        $user = $this->account('keluarga', $active);
        $this->post('/login', ['email' => $user->email, 'password' => $password])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak sesuai, atau akun belum aktif.'])
            ->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_missing_and_malformed_credentials_are_validated(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/login', ['email' => 'bukan-email', 'password' => ['array']])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_login_locks_email_ip_after_five_failures_and_allows_retry_after_expiry(): void
    {
        $user = $this->account('perawat');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'Salah12345678'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('email'));
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/perawat');
        $this->assertSame(0, RateLimiter::attempts(hash('sha256', $user->email).'|127.0.0.1'));
    }

    public function test_ip_limiter_covers_attempts_using_different_email_addresses(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/login', ['email' => "missing{$attempt}@example.test", 'password' => self::PASSWORD])
                ->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'other@example.test', 'password' => self::PASSWORD])->assertStatus(429)
            ->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_rotates_csrf_and_clears_history(): void
    {
        $user = $this->account('keluarga');
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/keluarga');
        session(['private_marker' => 'private']);
        $before = session()->getId();
        $token = session()->token();
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('private_marker');
        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame($token, session()->token());
        $response = $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->assertTrue($response->viewData('page')['clearHistory']);
        $this->get('/keluarga')->assertRedirect('/login');
    }

    public static function inactiveDestinations(): array
    {
        return [['/keluarga'], ['/login'], ['/dashboard']];
    }

    #[DataProvider('inactiveDestinations')]
    public function test_deactivation_is_enforced_on_next_request_including_guest_page(string $path): void
    {
        $user = $this->account('keluarga');
        $admin = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/keluarga');
        $before = session()->getId();
        $token = session()->token();
        app(AccountService::class)->setActive($admin, $user, false);
        // Simulasi request baru: guard harus memuat ulang akun dari provider database.
        Auth::forgetGuards();
        $this->get($path)->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame($token, session()->token());
    }

    public function test_password_change_revokes_an_existing_authenticated_session(): void
    {
        $user = $this->account('perawat');
        $admin = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/perawat');
        $this->get('/perawat')->assertOk();
        app(AccountService::class)->resetPassword($admin, $user, 'KataSandiPengganti456');
        Auth::forgetGuards();
        $this->get('/perawat')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'KataSandiPengganti456'])->assertRedirect('/perawat');
    }

    public function test_csrf_middleware_rejects_mutation_without_valid_token(): void
    {
        // Laravel melewati CSRF dalam unit test; aktifkan pemeriksaan aslinya untuk kasus ini.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $admin = $this->account();
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertStatus(419);
        $this->get('/login')->assertOk();
        $this->post('/login', ['_token' => session()->token(), 'email' => $admin->email, 'password' => self::PASSWORD])
            ->assertRedirect('/admin');
        $this->post('/admin/accounts/perawat', $this->accountPayload())->assertStatus(419);
        $this->post('/admin/accounts/perawat', ['_token' => session()->token(), ...$this->accountPayload()])
            ->assertRedirect('/admin/accounts');
        $this->post('/logout')->assertStatus(419);
    }
}
