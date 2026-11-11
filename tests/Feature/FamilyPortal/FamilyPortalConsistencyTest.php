<?php

namespace Tests\Feature\FamilyPortal;

use App\Services\ResidentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\FamilyPortalFeatureTestCase;
use Tests\Support\PostgresTestGuard;

class FamilyPortalConsistencyTest extends FamilyPortalFeatureTestCase
{
    protected bool $commitFixtures = true;

    private ?Process $reader = null;

    public static function pages(): array
    {
        return [['dashboard'], ['index'], ['detail']];
    }

    #[DataProvider('pages')]
    public function test_transfer_and_read_are_one_committed_snapshot_with_real_http_worker(string $page): void
    {
        $e = $this->resident();
        $other = $this->resident(changes: ['name' => 'Lansia tetap']);
        $room = $this->room();
        $this->enrollment($e, $room);
        $this->stay($e, $room);
        $next = $this->account('keluarga');
        $guard = PostgresTestGuard::fromEnvironment();
        $guard->assertSafe($this->app, DB::connection());
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        $path = match ($page) {
            'dashboard' => '/keluarga', 'index' => '/keluarga/residents', default => '/keluarga/residents/'.$e->id
        };
        $before = $this->snapshot();
        DB::beginTransaction();
        try {
            app(ResidentService::class)->reassignFamily($this->admin, $e, ['family_id' => $next->id,
                'current_family_id' => $this->family->id, 'confirm_reassignment' => true]);
            // Label master berubah dalam writer fixture yang belum commit; snapshot tarif tidak diubah.
            DB::table('rooms')->where('id', $room->id)->update(['name' => 'Label baru setelah transfer']);
            $stream = new InputStream;
            $this->reader = new Process([PHP_BINARY, 'tests/Support/family-portal-worker.php',
                json_encode(['actor_id' => $this->family->id, 'path' => $path], JSON_THROW_ON_ERROR)], base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'silansia_testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
            $this->reader->setInput($stream);
            $this->reader->setTimeout(30);
            $this->reader->start();
            $checkpoint = null;
            $deadline = microtime(true) + 15;
            do {
                $line = strtok($this->reader->getOutput(), "\n");
                $checkpoint = $line ? json_decode($line, true) : null;
                if ($checkpoint['snapshot'] ?? false) {
                    break;
                }
                if (! $this->reader->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($checkpoint['snapshot'] ?? false, 'Reader harus selesai SELECT tanpa menunggu writer.');
            $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $checkpoint['pid']);
            $guard->assertSafe($this->app, DB::connection());
            DB::commit();
            $stream->write('go'.PHP_EOL);
            $stream->close();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->reader->wait();
        $lines = explode("\n", trim($this->reader->getOutput()));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($this->reader->isSuccessful(), 'Worker gagal guard/infrastruktur: '.($result['exception'] ?? 'unknown'));
        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $props = $result['page']['props'];
        $records = match ($page) {
            'dashboard' => $props['residents'], 'index' => $props['residents']['data'], default => [$props['resident']]
        };
        $row = collect($records)->firstWhere('id', $e->id);
        $this->assertNotNull($row);
        $this->assertSame('Kamar keluarga', $row['service']['stay']['room_name']);
        $this->assertSame('123.45', $row['service']['enrollment']['monthly_price']);
        $this->get('/keluarga/residents/'.$e->id)->assertNotFound();
        $this->assertSame(1, $this->get('/keluarga')->assertOk()->viewData('page')['props']['summary']['residents']);
        $this->assertSame($other->id, $this->get('/keluarga/residents')->assertOk()->viewData('page')['props']['residents']['data'][0]['id']);
        $this->signIn($next);
        $new = $this->detail($e)['resident']['service'];
        $this->assertSame('Label baru setelah transfer', $new['stay']['room_name']);
        $this->assertSame('123.45', $new['enrollment']['monthly_price']);
        $after = $this->snapshot();
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['elderlies', 'rooms'])) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->reader?->isRunning()) {
            $this->reader->stop(1);
        }
        parent::tearDown();
    }
}
