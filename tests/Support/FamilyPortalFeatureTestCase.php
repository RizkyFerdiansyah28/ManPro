<?php

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class FamilyPortalFeatureTestCase extends PostgresFeatureTestCase
{
    protected User $family;

    protected User $admin;

    protected CarbonImmutable $time;

    protected bool $commitFixtures = false;

    private array $baseline = [];

    protected const TABLES = ['payments', 'invoices', 'notifications', 'fall_incident_actions', 'fall_incidents', 'gait_observations',
        'ai_detections', 'camera_operating_periods', 'elderly_stays', 'service_enrollments', 'cameras', 'rooms', 'elderlies',
        'service_classes', 'users', 'jobs', 'failed_jobs'];

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->commitFixtures) {
            $this->baseline = $this->snapshot();
        }
        $this->seed(ServiceClassSeeder::class);
        $this->family = $this->account('keluarga');
        $this->admin = $this->account();
        $this->time = CarbonImmutable::parse(DB::scalar('SELECT statement_timestamp()'))->utc()->startOfSecond();
        $this->signIn($this->family);
    }

    protected function signIn(?User $user): void
    {
        Auth::forgetGuards();
        session()->flush();
        if ($user) {
            $this->actingAs($user->fresh());
        }
    }

    protected function resident(?User $family = null, array $changes = []): Elderly
    {
        return Elderly::create(['family_id' => ($family ?? $this->family)->id, 'name' => 'Lansia fixture', 'birth_date' => '1950-01-01', ...$changes])->fresh();
    }

    protected function room(string $code = 'class_2', array $changes = []): Room
    {
        return Room::create(['service_class_id' => ServiceClass::where('code', $code)->sole()->id,
            'code' => 'F8-'.Str::uuid(), 'name' => 'Kamar keluarga', 'capacity' => $code === 'vip' ? 1 : 4, ...$changes])->fresh();
    }

    protected function enrollment(Elderly $e, Room $room, array $changes = []): ServiceEnrollment
    {
        return ServiceEnrollment::create(['elderly_id' => $e->id, 'service_class_id' => $room->service_class_id,
            'monthly_price' => '123.45', 'started_at' => $this->time->subDays(3), ...$changes])->fresh();
    }

    protected function stay(Elderly $e, Room $room, array $changes = []): ElderlyStay
    {
        return ElderlyStay::create(['elderly_id' => $e->id, 'room_id' => $room->id,
            'started_at' => $this->time->subDays(2), ...$changes])->fresh();
    }

    protected function detail(Elderly $e): array
    {
        return $this->get('/keluarga/residents/'.$e->id)->assertOk()->viewData('page')['props'];
    }

    protected function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $rows;
    }

    protected function tearDown(): void
    {
        if ($this->baseline !== []) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (self::TABLES as $table) {
                PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
                DB::table($table)->whereNotIn('id', array_column($this->baseline[$table], 'id'))->delete();
            }
            $this->assertSame($this->baseline, $this->snapshot());
        }
        parent::tearDown();
    }
}
