<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPostgres();
        DB::statement('LOCK TABLE users, sessions IN ACCESS EXCLUSIVE MODE');
        $this->assertEmptyUserReferences();

        DB::statement('ALTER TABLE users ALTER COLUMN id DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN id TYPE uuid USING id::text::uuid');
        DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        $this->assertPostgres();
        DB::statement('LOCK TABLE users, sessions IN ACCESS EXCLUSIVE MODE');
        $this->assertEmptyUserReferences();

        DB::statement('ALTER TABLE sessions DROP CONSTRAINT sessions_user_id_foreign');
        DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE bigint USING user_id::text::bigint');
        DB::statement('ALTER TABLE users ALTER COLUMN id TYPE bigint USING id::text::bigint');
        DB::statement("ALTER TABLE users ALTER COLUMN id SET DEFAULT nextval('users_id_seq'::regclass)");
    }

    private function assertPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Fondasi Silansia memerlukan PostgreSQL; migration dibatalkan.');
        }
    }

    private function assertEmptyUserReferences(): void
    {
        if (DB::table('users')->exists() || DB::table('sessions')->whereNotNull('user_id')->exists()) {
            throw new RuntimeException('Konversi kunci hanya aman untuk users kosong dan sesi anonim. Diperlukan pemetaan data tersendiri.');
        }
    }
};
