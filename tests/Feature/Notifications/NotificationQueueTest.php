<?php

namespace Tests\Feature\Notifications;

use App\Jobs\ReconcileFallNotifications;
use App\Support\StaffNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\NotificationFeatureTestCase;

class NotificationQueueTest extends NotificationFeatureTestCase
{
    private function enqueue(): void
    {
        $this->guard();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'silansia_testing']);
        Bus::dispatch(new ReconcileFallNotifications($this->incident->id));
        $this->assertDatabaseCount('jobs', 1);
    }

    private function worker(): void
    {
        $this->guard();
        $process = new Process([PHP_BINARY, 'tests/Support/notification-worker.php', json_encode(['action' => 'queue_once'])],
            base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing']);
        $process->setTimeout(40);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Queue worker gagal guard/infrastruktur.');
        $lines = array_filter(explode("\n", trim($process->getOutput())));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['result']);
    }

    public function test_real_database_queue_worker_uses_minimal_payload_and_creates_inbox_once(): void
    {
        $this->enqueue();
        $job = DB::table('jobs')->sole();
        $this->assertSame('notifications', $job->queue);
        foreach (['PRIVATE-STAFF-NOTE', 'PRIVATE-GATEWAY-SENTINEL', 'source_reference', 'metadata', $this->family->email, $this->nurse->email] as $private) {
            $this->assertStringNotContainsString($private, $job->payload);
        }
        $this->assertStringContainsString($this->incident->id, $job->payload);
        $this->worker();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 2);
        $this->enqueue();
        $before = $this->snapshot()['notifications'];
        $this->worker();
        $this->assertSame($before, $this->snapshot()['notifications']);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_transient_delivery_failure_retries_without_changing_source_or_exposing_sql(): void
    {
        $this->enqueue();
        $source = $this->incident->fresh()->getRawOriginal();
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT fixture_queue_failure CHECK (type <> 'silansia.fall_pending.v1') NOT VALID");
        try {
            $this->worker();
            $job = DB::table('jobs')->sole();
            $this->assertSame(1, $job->attempts);
            $this->assertNull($job->reserved_at);
            $this->assertDatabaseCount('notifications', 0);
            $this->assertDatabaseCount('failed_jobs', 0);
        } finally {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT fixture_queue_failure');
        }
        $this->guard();
        DB::table('jobs')->update(['available_at' => 0]);
        $this->worker();
        $this->assertSame($source, $this->incident->fresh()->getRawOriginal());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_exhausted_job_keeps_source_and_reconciliation_recovers_pending(): void
    {
        $this->enqueue();
        $this->guard();
        DB::table('jobs')->update(['attempts' => 3]);
        $this->worker();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $failed = DB::table('failed_jobs')->sole();
        foreach (['PRIVATE-STAFF-NOTE', 'PRIVATE-GATEWAY-SENTINEL', 'source_reference', 'SQLSTATE'] as $private) {
            $this->assertStringNotContainsString($private, $failed->payload.$failed->exception);
        }
        $this->assertSame('pending', $this->incident->fresh()->status);
        $this->assertSame(0, Artisan::call('silansia:reconcile-notifications', ['--limit' => '100']));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('failed_jobs', 1); // Tidak ada prune/reset histori gagal.
    }

    public function test_cli_scheduler_registration_and_bounded_reconciliation_are_verified(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'silansia:reconcile-notifications'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertSame(2, $events->first()->expiresAt);
        $this->assertSame(1, Artisan::call('silansia:reconcile-notifications', ['--limit' => '1001']));
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, Artisan::call('silansia:reconcile-notifications', ['--limit' => '1']));
        $this->assertStringContainsString('Diproses: 1; disimpan: 2; gagal: 0.', Artisan::output());
        $this->assertSame(0, Artisan::call('silansia:reconcile-notifications', ['--limit' => '1']));
        $this->assertStringContainsString('Diproses: 0; disimpan: 0; gagal: 0.', Artisan::output());
        $this->assertSame([StaffNotification::KIND], DB::table('notifications')->distinct()->pluck('type')->all());
    }
}
