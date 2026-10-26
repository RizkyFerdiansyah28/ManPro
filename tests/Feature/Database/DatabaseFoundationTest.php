<?php

namespace Tests\Feature\Database;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\Elderly;
use App\Models\ElderlyStay;
use App\Models\FallIncident;
use App\Models\GaitObservation;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Room;
use App\Models\ServiceClass;
use App\Models\ServiceEnrollment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ServiceClassSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostgresTestGuard;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    private User $family;

    private Elderly $elderly;

    private ServiceClass $class;

    private Room $room;

    private Camera $camera;

    private ServiceEnrollment $enrollment;

    private ElderlyStay $stay;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'silansia_testing') {
            $this->markTestSkipped('Uji constraint memerlukan PostgreSQL terisolasi; jalankan tests/Support/run-postgres.php.');
        }

        $database = config('database.connections.silansia_testing.database');
        if (! $this->app->environment('testing') || ! preg_match('/\Asilansia_test_[a-f0-9]{16}\z/', $database)
            || $database === config('database.connections.pgsql.database')) {
            $this->fail('Pengaman menolak database yang bukan database uji sementara.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame($database, DB::selectOne('SELECT current_database() AS name')->name);
        PostgresTestGuard::fromEnvironment()->assertSafe($this->app, DB::connection());
        DB::beginTransaction();

        $this->seed(ServiceClassSeeder::class);
        $this->class = ServiceClass::where('code', 'vip')->firstOrFail();
        $this->family = User::factory()->create(['role' => 'keluarga', 'is_active' => true]);
        $this->elderly = $this->family->elderlies()->create(['name' => 'Lansia Uji', 'birth_date' => '1950-01-01']);
        $this->room = $this->class->rooms()->create(['code' => 'VIP-UJI', 'name' => 'Kamar Uji', 'capacity' => 1]);
        $this->camera = $this->room->camera()->create(['name' => 'Kamera Uji', 'source_type' => 'webcam']);
        $this->enrollment = $this->elderly->serviceEnrollments()->create([
            'service_class_id' => $this->class->id, 'started_at' => '2026-10-01 00:00:00+00:00',
        ]);
        $this->stay = $this->elderly->stays()->create([
            'room_id' => $this->room->id, 'started_at' => '2026-10-01 00:00:00+00:00',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && config('database.default') === 'silansia_testing') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        parent::tearDown();
    }

    public function test_all_thirteen_tables_have_uuid_primary_keys_and_models_generate_them(): void
    {
        $gait = GaitObservation::create(['ai_detection_id' => $this->detection('gait')->id, 'category' => 'walking_normal']);
        $fall = FallIncident::create(['ai_detection_id' => $this->detection()->id]);
        $invoice = $this->invoice();
        $payment = $invoice->payments()->create([
            'submitted_by' => $this->family->id, 'amount' => 100, 'proof_path' => 'private/test-proof.png',
        ]);
        $notification = $this->family->notifications()->create([
            'type' => 'fixture', 'data' => ['message' => 'Pesan uji'], 'fall_incident_id' => $fall->id,
        ]);

        foreach ([$this->family, $this->elderly, $this->class, $this->room, $this->stay,
            $this->enrollment, $this->camera, $gait->aiDetection, $gait, $fall, $notification, $invoice, $payment] as $model) {
            $this->assertTrue(Str::isUuid($model->id), $model->getTable());
            $column = DB::selectOne('SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$model->getTable(), 'id']);
            $this->assertSame('uuid', $column->data_type);
            $this->assertFalse($model->getIncrementing());
        }
    }

    public function test_family_can_have_multiple_elderlies_and_age_is_not_stored(): void
    {
        $second = $this->family->elderlies()->create(['name' => 'Lansia Kedua', 'birth_date' => '1945-02-03']);
        $this->assertCount(2, $this->family->elderlies);
        $this->assertTrue($second->family->is($this->family));
        $this->assertSame('1950-01-01', $this->elderly->birth_date->toDateString());
        $this->assertArrayNotHasKey('age', $this->elderly->getAttributes());
    }

    public function test_room_class_camera_and_assignment_relationships(): void
    {
        $this->assertTrue($this->room->serviceClass->is($this->class));
        $this->assertTrue($this->class->rooms->contains($this->room));
        $this->assertTrue($this->camera->room->is($this->room));
        $this->assertTrue($this->room->camera->is($this->camera));
        $this->assertTrue($this->elderly->activeStay->is($this->stay));
        $this->assertTrue($this->stay->room->is($this->room));
        $this->assertTrue($this->stay->elderly->is($this->elderly));
        $this->assertTrue($this->elderly->activeServiceEnrollment->is($this->enrollment));
        $this->assertTrue($this->enrollment->serviceClass->is($this->class));
        $this->assertTrue($this->enrollment->elderly->is($this->elderly));
        $this->assertTrue($this->class->serviceEnrollments->contains($this->enrollment));
        $this->assertTrue($this->room->stays->contains($this->stay));
    }

    public function test_camera_per_room_is_unique(): void
    {
        $this->rejects(fn () => Camera::create(['room_id' => $this->room->id, 'name' => 'Duplikat', 'source_type' => 'webcam']), '23505', 'cameras_room_id_unique');
    }

    public function test_only_one_active_stay_and_enrollment_are_allowed(): void
    {
        $this->rejects(fn () => ElderlyStay::create(['elderly_id' => $this->elderly->id, 'room_id' => $this->room->id, 'started_at' => now()]), '23505', 'elderly_stays_one_active');
        $this->rejects(fn () => ServiceEnrollment::create(['elderly_id' => $this->elderly->id, 'service_class_id' => $this->class->id, 'started_at' => now()]), '23505', 'service_enrollments_one_active');
    }

    public function test_historical_stays_and_enrollments_are_preserved_when_new_ones_start(): void
    {
        $this->stay->update(['ended_at' => '2026-10-10 00:00:00+00:00']);
        $this->enrollment->update(['ended_at' => '2026-10-10 00:00:00+00:00']);
        $stay = $this->elderly->stays()->create(['room_id' => $this->room->id, 'started_at' => '2026-10-10 00:00:00+00:00']);
        $enrollment = $this->elderly->serviceEnrollments()->create(['service_class_id' => $this->class->id, 'started_at' => '2026-10-10 00:00:00+00:00']);
        $this->assertCount(2, $this->elderly->stays);
        $this->assertCount(2, $this->elderly->serviceEnrollments);
        $this->assertTrue($this->elderly->activeStay->is($stay));
        $this->assertTrue($this->elderly->activeServiceEnrollment->is($enrollment));
    }

    public function test_foreign_keys_reject_unknown_parents_and_preserve_history(): void
    {
        $this->invoice();
        $this->rejects(fn () => Elderly::create(['family_id' => (string) Str::uuid(), 'name' => 'Uji', 'birth_date' => '1950-01-01']), '23503');
        $this->rejects(fn () => $this->family->delete(), '23001');
        $this->rejects(fn () => $this->camera->room->delete(), '23001');
        $this->rejects(fn () => $this->enrollment->delete(), '23001');
    }

    public function test_positive_capacity_and_valid_assignment_periods_are_enforced(): void
    {
        $this->rejects(fn () => $this->room->fresh()->update(['capacity' => 0]), '23514', 'rooms_check_0');
        $this->rejects(fn () => $this->room->fresh()->update(['capacity' => -1]), '23514', 'rooms_check_0');
        $this->rejects(fn () => $this->stay->fresh()->update(['ended_at' => $this->stay->started_at]), '23514', 'elderly_stays_check_0');
        $this->rejects(fn () => $this->enrollment->fresh()->update(['ended_at' => '2020-01-01']), '23514', 'service_enrollments_check_0');
    }

    public function test_duplicate_external_events_and_duplicate_incidents_are_rejected(): void
    {
        $event = $this->detection();
        $this->rejects(fn () => $this->detection('fall', $event->external_event_id), '23505', 'ai_detections_external_event_id_unique');
        FallIncident::create(['ai_detection_id' => $event->id]);
        $this->rejects(fn () => FallIncident::create(['ai_detection_id' => $event->id]), '23505', 'fall_incidents_ai_detection_id_unique');
        $gait = GaitObservation::create(['ai_detection_id' => $this->detection('gait')->id, 'category' => 'walking_normal']);
        $this->rejects(fn () => GaitObservation::create(['ai_detection_id' => $gait->ai_detection_id, 'category' => 'walking_normal']), '23505', 'gait_observations_ai_detection_id_unique');
    }

    public function test_pending_fall_has_no_confirmed_identity_and_accepts_caregiver_verification(): void
    {
        $incident = FallIncident::create(['ai_detection_id' => $this->detection()->id])->fresh();
        $this->assertSame('pending', $incident->status);
        $this->assertNull($incident->elderly_id);
        $this->assertNull($incident->responding_caregiver_id);
        $caregiver = User::factory()->create(['role' => 'perawat']);
        $incident->update(['status' => 'acknowledged', 'responding_caregiver_id' => $caregiver->id, 'acknowledged_at' => now()]);
        $this->rejects(fn () => $incident->fresh()->update(['elderly_id' => $this->elderly->id]), '23514', 'fall_incidents_check_2');
        $incident->update(['elderly_id' => $this->elderly->id, 'verified_by' => $caregiver->id, 'verified_at' => now()]);
        $incident->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->assertTrue($incident->respondingCaregiver->is($caregiver));
        $this->assertTrue($incident->verifier->is($caregiver));
        $this->assertTrue($incident->elderly->is($this->elderly));
        $this->assertTrue($incident->aiDetection->fallIncident->is($incident));
        $this->assertTrue($caregiver->respondingIncidents->contains($incident));
        $this->assertTrue($caregiver->verifiedIncidents->contains($incident));
        $this->assertTrue($this->elderly->fallIncidents->contains($incident));
    }

    public function test_false_alarm_can_be_closed_without_elderly_identity(): void
    {
        $caregiver = User::factory()->create(['role' => 'perawat']);
        $incident = FallIncident::create([
            'ai_detection_id' => $this->detection()->id, 'status' => 'false_alarm',
            'responding_caregiver_id' => $caregiver->id, 'acknowledged_at' => now(), 'resolved_at' => now(),
        ]);
        $this->assertNull($incident->elderly_id);
        $this->assertNull($incident->verified_at);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_shared_room_gait_has_nullable_identity_and_provisional_categories(): void
    {
        $class = ServiceClass::where('code', 'class_2')->firstOrFail();
        $room = $class->rooms()->create(['code' => 'SHARED', 'name' => 'Bersama', 'capacity' => 4]);
        $camera = $room->camera()->create(['name' => 'Bersama', 'source_type' => 'webcam']);
        foreach (['walking_normal', 'walking_abnormal', 'no_walking_detected'] as $category) {
            $event = $this->detection('gait', camera: $camera);
            $gait = GaitObservation::create(['ai_detection_id' => $event->id, 'category' => $category])->fresh();
            $this->assertNull($gait->elderly_id);
            $this->assertSame('unknown', $gait->identity_basis);
            $this->assertTrue($gait->aiDetection->camera->room->is($room));
        }
        $this->rejects(fn () => GaitObservation::create(['ai_detection_id' => $this->detection('gait')->id, 'category' => 'clinical_score']), '23514', 'gait_observations_check_0');
    }

    public function test_vip_gait_records_documented_identity_assumption(): void
    {
        $gait = GaitObservation::create([
            'ai_detection_id' => $this->detection('gait')->id, 'elderly_id' => $this->elderly->id,
            'category' => 'walking_normal', 'identity_basis' => 'vip_room_single_resident',
        ]);
        $this->assertTrue($gait->elderly->is($this->elderly));
        $this->assertTrue($gait->aiDetection->gaitObservation->is($gait));
        $this->assertTrue($this->elderly->gaitObservations->contains($gait));
    }

    public function test_ai_confidence_and_event_instant_are_preserved(): void
    {
        $event = $this->detection();
        $event->update(['occurred_at' => '2026-10-21T18:00:00+07:00', 'confidence' => 0.85, 'metadata' => ['model_version' => 'test']]);
        $this->assertSame('2026-10-21 11:00:00', $event->fresh()->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('0.85000', $event->fresh()->confidence);
        $this->assertSame(['model_version' => 'test'], $event->fresh()->metadata);
        $this->assertTrue($this->camera->aiDetections->contains($event));
        $this->rejects(fn () => $event->fresh()->update(['confidence' => 1.1]), '23514', 'ai_detections_check_1');
    }

    public function test_native_database_notifications_have_recipient_incident_message_and_read_state(): void
    {
        $incident = FallIncident::create(['ai_detection_id' => $this->detection()->id]);
        $notification = $this->family->notifications()->create([
            'type' => 'test', 'data' => ['message' => 'Pesan uji'], 'fall_incident_id' => $incident->id,
        ]);
        $this->assertInstanceOf(Notification::class, $notification);
        $this->assertSame('Pesan uji', $notification->fresh()->data['message']);
        $this->assertTrue($notification->notifiable->is($this->family));
        $this->assertTrue($notification->fallIncident->is($incident));
        $this->assertTrue($incident->notifications->contains($notification));
        $this->assertCount(1, $this->family->unreadNotifications()->get());
        $notification->markAsRead();
        $this->assertCount(0, $this->family->unreadNotifications()->get());
        $this->assertCount(1, $this->family->readNotifications()->get());
    }

    public function test_monthly_invoice_and_three_calendar_day_grace_constraints(): void
    {
        $invoice = $this->invoice();
        $this->assertSame('2026-11-03', $invoice->grace_period_ends_on->toDateString());
        $this->assertTrue($invoice->elderly->is($this->elderly));
        $this->assertTrue($invoice->serviceEnrollment->is($this->enrollment));
        $this->assertTrue($this->enrollment->invoices->contains($invoice));
        $this->assertTrue($this->elderly->invoices->contains($invoice));
        $this->rejects(fn () => $invoice->fresh()->update(['grace_period_ends_on' => '2026-11-02']), '23514', 'invoices_check_3');
        $this->assertSame('2026-11-03', $invoice->fresh()->grace_period_ends_on->toDateString());
        $this->rejects(fn () => $invoice->fresh()->update(['amount' => -1]), '23514', 'invoices_check_0');
        $this->assertSame('100.00', $invoice->fresh()->amount);
        $this->rejects(fn () => $this->invoice(), '23505', 'invoices_elderly_id_billing_month_unique');
    }

    public function test_invoice_cannot_reference_another_elderlys_enrollment(): void
    {
        $other = $this->family->elderlies()->create(['name' => 'Kedua', 'birth_date' => '1955-01-01']);
        $invoice = $this->invoice();
        $this->rejects(fn () => $invoice->fresh()->update(['elderly_id' => $other->id]), '23503', 'invoices_enrollment_elderly_foreign');
    }

    public function test_payment_proof_review_relationships_and_private_serialization(): void
    {
        $invoice = $this->invoice();
        $admin = User::factory()->create(['role' => 'admin']);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'submitted_by' => $this->family->id,
            'amount' => 100, 'proof_path' => 'private/test.png', 'submitted_at' => now(),
        ]);
        $this->rejects(fn () => $payment->fresh()->update(['amount' => -1]), '23514', 'payments_check_0');
        $payment->refresh()->update(['status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
        $this->assertTrue($payment->invoice->is($invoice));
        $this->assertTrue($payment->submitter->is($this->family));
        $this->assertTrue($payment->verifier->is($admin));
        $this->assertTrue($invoice->payments->contains($payment));
        $this->assertTrue($this->family->submittedPayments->contains($payment));
        $this->assertTrue($admin->verifiedPayments->contains($payment));
        $this->assertArrayNotHasKey('proof_path', $payment->toArray());
        $this->assertArrayNotHasKey('verification_notes', $payment->toArray());
    }

    public function test_unpaid_billing_does_not_prevent_safety_records(): void
    {
        $invoice = $this->invoice();
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $incident = FallIncident::create(['ai_detection_id' => $this->detection()->id]);
        $this->assertNotNull($incident->id);
        $this->assertNull($incident->elderly_id);
        $this->assertArrayNotHasKey('invoice_id', $incident->getAttributes());
    }

    public function test_service_class_seeder_is_idempotent_preserves_prices_and_creates_no_accounts(): void
    {
        $ids = ServiceClass::orderBy('code')->pluck('id')->all();
        $users = User::count();
        $this->assertSame(0, ServiceClass::whereNotNull('monthly_price')->count());
        $this->class->update(['monthly_price' => '123.45']);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($ids, ServiceClass::orderBy('code')->pluck('id')->all());
        $this->assertSame($users, User::count());
        $this->assertSame(3, ServiceClass::count());
        $this->assertSame('123.45', $this->class->fresh()->monthly_price);
        $this->assertTrue($this->class->family_live_cctv);
        $this->assertTrue($this->class->instant_verified_emergency);
        $this->assertTrue($this->class->daily_gait_report);
        $class2 = ServiceClass::where('code', 'class_2')->firstOrFail();
        $this->assertFalse($class2->family_live_cctv);
        $this->assertTrue($class2->instant_verified_emergency);
        $this->assertFalse($class2->daily_gait_report);
        $this->assertTrue($class2->weekly_gait_report);
        $this->assertTrue($class2->monthly_gait_report);
        $class3 = ServiceClass::where('code', 'class_3')->firstOrFail();
        $this->assertTrue($class3->instant_verified_emergency);
        $this->assertFalse($class3->weekly_gait_report);
        $this->assertTrue($class3->monthly_gait_report);
        $this->assertTrue($class3->verified_incident_summary);
    }

    public function test_user_role_constraint_inactive_default_and_protected_mass_assignment(): void
    {
        $user = User::create(['name' => 'Uji', 'email' => 'mass@example.test', 'password' => 'test-password', 'role' => 'admin', 'is_active' => true])->fresh();
        $this->assertSame('keluarga', $user->role);
        $this->assertFalse($user->is_active);
        $this->rejects(fn () => DB::table('users')->where('id', $user->id)->update(['role' => 'superadmin']), '23514', 'users_check_0');
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_camera_reference_is_encrypted_and_hidden(): void
    {
        $this->camera->update(['source_reference' => 'test-only-source']);
        $this->assertSame('test-only-source', $this->camera->fresh()->source_reference);
        $this->assertNotSame('test-only-source', DB::table('cameras')->where('id', $this->camera->id)->value('source_reference'));
        $this->assertArrayNotHasKey('source_reference', $this->camera->toArray());
    }

    public function test_sessions_use_uuid_user_reference_and_native_string_session_id(): void
    {
        $user = User::factory()->create();
        $session = ['id' => Str::random(40), 'user_id' => $user->id, 'payload' => 'test', 'last_activity' => 1];
        DB::table('sessions')->insert($session);
        $before = (array) DB::table('sessions')->where('id', $session['id'])->sole();
        $user->delete();
        $this->assertSame(1, DB::table('sessions')->where('id', $session['id'])->count());
        $after = (array) DB::table('sessions')->where('id', $session['id'])->sole();
        $this->assertSame($session['id'], $after['id']);
        $this->assertSame($session['payload'], $after['payload']);
        $this->assertNull($after['user_id']);
        $this->assertSame(array_replace($before, ['user_id' => null]), $after);
        $this->rejects(fn () => DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => (string) Str::uuid(), 'payload' => 'test', 'last_activity' => 1]), '23503', 'sessions_user_id_foreign');
    }

    public function test_mermaid_erd_columns_types_and_nullability_match_the_migrated_schema(): void
    {
        $document = file_get_contents(base_path('docs/database/SILANSIA_ERD.md'));
        preg_match_all('/^    ([a-z_]+) \{\R(.*?)^    \}/ms', $document, $entities, PREG_SET_ORDER);
        $this->assertCount(14, $entities);
        $types = [
            'uuid' => 'uuid', 'varchar' => 'character varying', 'text' => 'text',
            'numeric' => 'numeric', 'integer' => 'integer', 'boolean' => 'boolean',
            'date' => 'date', 'timestamp' => 'timestamp without time zone',
            'timestamptz' => 'timestamp with time zone', 'jsonb' => 'jsonb',
        ];
        foreach ($entities as [, $table, $body]) {
            $columns = collect(DB::select('SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]))->keyBy('column_name');
            preg_match_all('/^\s+(\w+)\s+(\w+)([^\r\n]*)/m', $body, $fields, PREG_SET_ORDER);
            $this->assertCount(count($fields), $columns, $table);
            foreach ($fields as [, $type, $column, $details]) {
                $this->assertTrue($columns->has($column), $table.'.'.$column);
                $this->assertSame($types[$type], $columns[$column]->data_type, $table.'.'.$column);
                $this->assertSame(str_contains($details, 'NULL') ? 'YES' : 'NO', $columns[$column]->is_nullable, $table.'.'.$column);
            }
        }
    }

    public static function monetaryColumns(): array
    {
        return [
            'harga kelas' => ['service_classes', 'monthly_price', 'service_classes_check_2'],
            'harga enrollment' => ['service_enrollments', 'monthly_price', 'service_enrollments_check_1'],
            'nominal invoice' => ['invoices', 'amount', 'invoices_check_0'],
            'nominal pembayaran' => ['payments', 'amount', 'payments_check_0'],
        ];
    }

    public static function monetaryFields(): array
    {
        return array_map(fn (array $data) => array_slice($data, 0, 2), self::monetaryColumns());
    }

    #[DataProvider('monetaryFields')]
    public function test_nan_monetary_values_are_rejected_by_the_exact_constraint(string $table, string $column): void
    {
        $record = $this->monetaryRecord($table);
        $before = DB::table($table)->where('id', $record->id)->value($column);
        $this->rejects(
            fn () => DB::table($table)->where('id', $record->id)->update([$column => 'NaN']),
            '23514', $table.'_'.$column.'_not_nan',
        );
        $this->assertSame($before, DB::table($table)->where('id', $record->id)->value($column));
    }

    #[DataProvider('monetaryColumns')]
    public function test_negative_monetary_values_are_rejected_independently(string $table, string $column, string $constraint): void
    {
        $record = $this->monetaryRecord($table);
        $before = DB::table($table)->where('id', $record->id)->value($column);
        $this->rejects(
            fn () => DB::table($table)->where('id', $record->id)->update([$column => '-0.01']),
            '23514', $constraint,
        );
        $this->assertSame($before, DB::table($table)->where('id', $record->id)->value($column));
    }

    #[DataProvider('monetaryFields')]
    public function test_valid_money_and_existing_null_rules_remain_supported(string $table, string $column): void
    {
        $record = $this->monetaryRecord($table);
        foreach (['0.00', '123.45', '999999999999.99'] as $value) {
            DB::table($table)->where('id', $record->id)->update([$column => $value]);
            $this->assertSame($value, $record->fresh()->getAttribute($column));
        }
        if ($column === 'monthly_price') {
            DB::table($table)->where('id', $record->id)->update([$column => null]);
            $this->assertNull($record->fresh()->getAttribute($column));
        } else {
            $this->rejects(fn () => DB::table($table)->where('id', $record->id)->update([$column => null]), '23502');
            $this->assertSame('999999999999.99', $record->fresh()->getAttribute($column));
        }
    }

    #[DataProvider('monetaryFields')]
    public function test_nan_preflight_refuses_existing_invalid_data_without_changing_it(string $table, string $column): void
    {
        $record = $this->monetaryRecord($table);
        $migration = require database_path('migrations/2026_10_21_000003_reject_nan_monetary_values.php');
        // Hanya di transaksi fixture DB acak: simulasikan kondisi sebelum migration aditif.
        $migration->down();
        DB::table($table)->where('id', $record->id)->update([$column => 'NaN']);
        $before = DB::table($table)->where('id', $record->id)->first();
        $migrationRecords = DB::table('migrations')->orderBy('id')->get()->all();
        try {
            DB::transaction(fn () => $migration->up());
            $this->fail('Preflight harus menolak data NaN yang sudah ada.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(\RuntimeException::class, get_class($exception));
            $this->assertSame('Ditemukan nominal NaN; migration dibatalkan tanpa mengubah data. Perlu koreksi nominal dengan persetujuan terpisah.', $exception->getMessage());
        }
        $this->assertEquals($before, DB::table($table)->where('id', $record->id)->first());
        $this->assertEquals($migrationRecords, DB::table('migrations')->orderBy('id')->get()->all());
        $constraints = DB::select("SELECT conname FROM pg_catalog.pg_constraint WHERE connamespace = 'public'::regnamespace AND conname = ANY (ARRAY[
            'service_classes_monthly_price_not_nan', 'service_enrollments_monthly_price_not_nan',
            'invoices_amount_not_nan', 'payments_amount_not_nan'])");
        $this->assertSame([], $constraints);
    }

    private function monetaryRecord(string $table): Model
    {
        return match ($table) {
            'service_classes' => $this->class,
            'service_enrollments' => $this->enrollment,
            'invoices' => $this->invoice(),
            'payments' => $this->invoice()->payments()->create([
                'submitted_by' => $this->family->id, 'amount' => 100, 'proof_path' => 'private/test.png',
            ]),
        };
    }

    private function detection(string $type = 'fall', ?string $externalId = null, ?Camera $camera = null): AiDetection
    {
        return AiDetection::create([
            'camera_id' => ($camera ?? $this->camera)->id, 'event_type' => $type,
            'external_event_id' => $externalId ?? (string) Str::uuid(), 'occurred_at' => now(),
        ]);
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'number' => 'TEST-'.Str::uuid(), 'elderly_id' => $this->elderly->id,
            'service_enrollment_id' => $this->enrollment->id, 'billing_month' => '2026-10-01',
            'amount' => 100, 'due_date' => '2026-10-31', 'grace_period_ends_on' => '2026-11-03',
        ]);
    }

    private function rejects(callable $operation, string $sqlState, ?string $constraint = null): void
    {
        try {
            // Savepoint menjaga transaksi tes tetap bisa dipakai setelah PostgreSQL menolak SQL.
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString('"'.$constraint.'"', $exception->errorInfo[2]);
            }

            return;
        }

        $this->fail('Database tidak menolak pelanggaran constraint.');
    }
}
