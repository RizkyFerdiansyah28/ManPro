<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class PostgresFeatureTestCase extends TestCase
{
    protected const PASSWORD = 'PasswordUjiAman123';

    private bool $databaseVerified = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'silansia_testing') {
            $this->markTestSkipped('Uji akun memerlukan runner PostgreSQL terisolasi tests/Support/run-postgres.php.');
        }
        // Wajib lolos pemeriksaan identitas runner sebelum transaksi atau fixture dibuat.
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        $this->databaseVerified = true;
        DB::beginTransaction();
        Cache::flush();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if ($this->databaseVerified) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    protected function account(string $role = 'admin', bool $active = true, array $attributes = []): User
    {
        return User::factory()->create([...$attributes, 'role' => $role, 'is_active' => $active, 'password' => self::PASSWORD]);
    }

    protected function accountPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Akun Uji', 'email' => 'baru@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }
}
