<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('LOCK TABLE notifications IN SHARE ROW EXCLUSIVE MODE');
        if (DB::table('notifications')->where('type', 'silansia.fall_pending.v1')->whereNull('fall_incident_id')->exists()
            || DB::selectOne("SELECT 1 FROM notifications WHERE type='silansia.fall_pending.v1'
                GROUP BY notifiable_type,notifiable_id,type,fall_incident_id HAVING count(*)>1 LIMIT 1")) {
            throw new RuntimeException('Hardening notifikasi ditolak: terdapat notifikasi staf tanpa insiden atau duplikat. Data tidak diubah.');
        }
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_staff_incident_check
            CHECK (type <> 'silansia.fall_pending.v1' OR fall_incident_id IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX notifications_staff_incident_unique
            ON notifications (notifiable_type,notifiable_id,type,fall_incident_id)
            WHERE type='silansia.fall_pending.v1' AND fall_incident_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('LOCK TABLE notifications IN SHARE ROW EXCLUSIVE MODE');
        if (DB::table('notifications')->where('type', 'silansia.fall_pending.v1')->exists()) {
            throw new RuntimeException('Rollback ditolak karena notifikasi staf masih memerlukan pengaman deduplikasi. Gunakan perubahan maju.');
        }
        DB::statement('DROP INDEX notifications_staff_incident_unique');
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_staff_incident_check');
    }
};
