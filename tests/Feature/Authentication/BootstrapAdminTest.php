<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresFeatureTestCase;

class BootstrapAdminTest extends PostgresFeatureTestCase
{
    public function test_bootstrap_requires_interaction_and_never_supplies_default_credentials(): void
    {
        $before = User::count();
        $this->artisan('silansia:bootstrap-admin', ['--no-interaction' => true])
            ->expectsOutputToContain('harus dijalankan secara interaktif')->assertFailed();
        $this->assertSame($before, User::count());
    }

    public function test_interactive_bootstrap_creates_one_active_admin_and_refuses_duplicate_bootstrap(): void
    {
        $this->artisan('silansia:bootstrap-admin')
            ->expectsQuestion('Nama Admin', 'Admin Uji')
            ->expectsQuestion('Email Admin', '  ADMIN.UJI@example.test  ')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)', self::PASSWORD)
            ->expectsQuestion('Konfirmasi kata sandi', self::PASSWORD)
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->expectsOutputToContain('Admin awal berhasil dibuat')->assertSuccessful();
        $admin = User::where('email', 'admin.uji@example.test')->sole();
        $this->assertTrue(Str::isUuid($admin->id));
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $before = $admin->getAttributes();
        $this->artisan('silansia:bootstrap-admin')->expectsOutputToContain('sudah tersedia')->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $admin->fresh()->getAttributes());
    }

    public static function invalidInput(): array
    {
        return ['weak' => ['admin@example.test', 'lemah', 'lemah'],
            'confirmation' => ['admin@example.test', self::PASSWORD, 'Berbeda123456'],
            'invalid email' => ['bukan-email', self::PASSWORD, self::PASSWORD],
            'existing email case' => ['EXISTING@example.test', self::PASSWORD, self::PASSWORD]];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_bootstrap_preserves_existing_accounts(string $email, string $password, string $confirmation): void
    {
        $existing = $this->account('keluarga', true, ['email' => 'Existing@example.test']);
        $before = $existing->fresh()->getAttributes();
        $this->artisan('silansia:bootstrap-admin')
            ->expectsQuestion('Nama Admin', 'Admin Uji')
            ->expectsQuestion('Email Admin', $email)
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)', $password)
            ->expectsQuestion('Konfirmasi kata sandi', $confirmation)
            ->doesntExpectOutputToContain($password)->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertFalse(User::where('role', 'admin')->exists());
    }
}
