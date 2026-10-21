<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guest_is_directed_to_login_and_login_page_exists(): void
    {
        $this->withoutVite();
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')->where('auth.user', null));
    }

    public function test_guests_cannot_access_protected_routes(): void
    {
        foreach (['/dashboard', '/admin', '/admin/accounts', '/admin/accounts/create', '/perawat', '/keluarga'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->post('/admin/accounts/perawat', [])->assertRedirect('/login');
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_public_registration_and_public_password_reset_are_absent(): void
    {
        $this->withoutVite();
        foreach (['/register', '/forgot-password', '/reset-password', '/silansia/bootstrap-admin'] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path)->assertNotFound();
        }
    }

    public function test_server_errors_do_not_expose_exception_details_in_html_or_json(): void
    {
        $this->withoutVite();
        config(['app.debug' => true]);
        Log::spy();
        // Route ini hanya terdaftar pada lifecycle aplikasi tes, bukan route produk.
        Route::get('/test-server-error', fn () => throw new \RuntimeException('Detail internal fixture'));
        $this->get('/test-server-error')->assertStatus(500)->assertDontSee('Detail internal fixture')
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500)->missing('exception')->missing('trace'));
        $this->getJson('/test-server-error')->assertStatus(500)
            ->assertExactJson(['message' => 'Layanan sedang bermasalah. Silakan coba kembali nanti.'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
