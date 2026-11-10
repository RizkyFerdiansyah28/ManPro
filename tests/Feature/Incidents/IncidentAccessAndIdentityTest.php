<?php

namespace Tests\Feature\Incidents;

use App\Models\ElderlyStay;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IncidentFeatureTestCase;

class IncidentAccessAndIdentityTest extends IncidentFeatureTestCase
{
    public function test_incident_mutation_requires_real_csrf_and_valid_token_succeeds(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $before = $this->monitoringSnapshot();
        $this->post($this->path('acknowledge'), $this->command('acknowledge'))->assertStatus(419);
        $this->assertSame($before, $this->monitoringSnapshot());
        $this->post($this->path('acknowledge'), [...$this->command('acknowledge'), '_token' => session()->token()])->assertStatus(303);
        $this->assertSame('acknowledged', $this->incident->fresh()->status);
    }

    public function test_existing_session_loses_access_after_caregiver_deactivation(): void
    {
        Auth::guard('web')->login($this->nurse);
        $this->detail();
        $this->nurse->forceFill(['is_active' => false])->save();
        $this->assertFalse($this->nurse->fresh()->is_active);
        Auth::forgetGuards(); // Batas request nyata membaca user sesi kembali dari database.
        $before = $this->monitoringSnapshot();
        $this->postJson($this->path('acknowledge'), $this->command('acknowledge'))->assertRedirect('/login');
        $this->assertGuest();
        $this->assertOnlySessionRevocationChanged($before, $this->nurse);
    }

    public static function roles(): array
    {
        return [['admin', true, 200, 403], ['perawat', true, 200, 200], ['keluarga', true, 403, 403],
            ['perawat', false, 302, 302], ['admin', false, 302, 302], [null, true, 302, 401]];
    }

    #[DataProvider('roles')]
    public function test_role_boundaries_and_unknown_ids_do_not_grant_or_disclose_mutation(?string $role, bool $active, int $read, int $write): void
    {
        Auth::logout();
        session()->flush();
        $actor = $role ? $this->account($role, $active) : null;
        if ($actor) {
            $this->signIn($actor);
        }
        foreach (['/monitoring/incidents', $this->path()] as $path) {
            $this->get($path)->assertStatus($read);
        }
        if ($actor) {
            // Request GET akun nonaktif sudah mencabut sesi. Kasus POST mulai dari sesi sendiri.
            $this->signIn($actor);
        }
        $before = $this->monitoringSnapshot();
        $this->postJson($this->path('acknowledge'), $this->command('acknowledge'))->assertStatus($write);
        if ($actor && ! $active) {
            $this->assertOnlySessionRevocationChanged($before, $actor);
        } elseif ($write !== 200) {
            $this->assertSame($before, $this->monitoringSnapshot());
        }
        if ($read === 200 && $write === 403) {
            foreach (['acknowledge', 'verify', 'resolve', 'false_alarm'] as $action) {
                $this->postJson('/monitoring/incidents/'.Str::uuid().'/'.str_replace('_', '-', $action), $this->command($action))->assertForbidden();
            }
            $this->getJson($this->path('resident-options'))->assertForbidden();
        }
    }

    public function test_detail_and_journal_are_whitelisted_and_gets_do_not_mutate_domain_data(): void
    {
        $this->monitorCamera->update(['source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        $this->perform('acknowledge', ['notes' => 'PRIVATE-ACTION-NOTE']);
        $before = $this->monitoringSnapshot();
        foreach ([$this->nurse, $this->admin] as $actor) {
            $this->signIn($actor);
            $response = $this->get($this->path())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $props = $response->viewData('page')['props'];
            $this->assertSame('PRIVATE-NOTES-SENTINEL', $props['incident']['legacy_notes']);
            $this->assertSame('PRIVATE-ACTION-NOTE', $props['actions']['data'][0]['notes']);
            $encoded = json_encode($props);
            foreach (['source_reference', 'fixture-secret', 'metadata', 'PRIVATE-GATEWAY-SENTINEL', 'external_event_id',
                'request_fingerprint', 'request_id', 'responding_caregiver_id', 'verified_by', 'birth_date', 'medical_notes',
                'monthly_price', 'family_id', 'resident_url', 'configuration_url'] as $private) {
                $this->assertStringNotContainsString($private, $encoded);
            }
            $this->assertFalse($response->headers->has('X-Inertia-Devtools-Id'));
            if ($actor->role === 'admin') {
                $this->assertNull($props['incident']['version']);
                $this->assertSame([], $props['incident']['allowed_actions']);
            }
            $listing = $this->get('/monitoring/incidents')->assertOk()->viewData('page')['props'];
            $this->assertStringNotContainsString('PRIVATE-ACTION-NOTE', json_encode($listing));
            $this->assertStringNotContainsString('PRIVATE-NOTES-SENTINEL', json_encode($listing));
        }
        $this->assertSame($before, $this->monitoringSnapshot());
    }

    public static function invalidInputs(): array
    {
        return [['status', 'resolved'], ['responding_caregiver_id', 'spoofed'], ['verified_by', 'spoofed'],
            ['acknowledged_at', '2026-01-01'], ['ai_detection_id', 'spoofed'], ['elderly_id', 'spoofed'],
            ['notes', ['private']], ['notes', str_repeat('a', 2001)], ['confirm', false],
            ['expected_version', 'invalid'], ['request_id', 'invalid']];
    }

    #[DataProvider('invalidInputs')]
    public function test_form_rejects_manipulated_fields_without_flashing_private_input(string $field, mixed $value): void
    {
        $before = $this->monitoringSnapshot();
        $this->postJson($this->path('acknowledge'), $this->command('acknowledge', [$field => $value]))->assertUnprocessable();
        $this->post($this->path('acknowledge'), $this->command('acknowledge', [$field => $value]))
            ->assertStatus(303)->assertSessionHasErrors();
        $this->assertNull(session()->getOldInput('notes'));
        $this->assertSame($before, $this->monitoringSnapshot());
    }

    public function test_verify_requires_explicit_identity_mode_and_nonblank_note_and_rejects_unscoped_id(): void
    {
        $this->perform('acknowledge');
        foreach ([['notes' => '  '], ['identity_mode' => null], ['identity_mode' => 'known'],
            ['identity_mode' => 'known', 'elderly_id' => (string) Str::uuid()],
            ['identity_mode' => 'unknown', 'elderly_id' => (string) Str::uuid()]] as $changes) {
            $before = $this->monitoringSnapshot();
            $this->postJson($this->path('verify'), $this->command('verify', $changes))->assertUnprocessable();
            $this->assertSame($before, $this->monitoringSnapshot());
        }
    }

    public function test_historical_shared_candidates_are_manual_and_independent_of_current_state_and_billing(): void
    {
        $this->perform('acknowledge');
        $one = $this->resident();
        $two = $this->resident();
        foreach ([$one, $two] as $resident) {
            ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $this->monitorPeriod->room_id,
                'started_at' => $this->eventTime->subDay(), 'ended_at' => $this->eventTime->addMinutes(10)]);
        }
        $one->update(['is_active' => false]);
        $this->monitorCamera->room->update(['is_active' => false]);
        $this->monitorCamera->room->serviceClass->update(['is_active' => false, 'monthly_price' => null]);
        $destination = $this->room();
        $this->monitorCamera->update(['room_id' => $destination->id, 'is_active' => false]);
        $rows = $this->getJson($this->path('resident-options'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$one->id, $two->id], array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertSame(['id', 'name'], array_keys($row));
        }
        $this->assertNull($this->incident->fresh()->elderly_id);
        $this->perform('verify', ['identity_mode' => 'known', 'elderly_id' => $one->id]);
        $this->assertSame($one->id, $this->incident->fresh()->elderly_id);
        $detail = $this->detail()['incident'];
        $this->assertSame($this->monitorPeriod->room->code, $detail['context']['room_code']);
        $this->assertSame($one->name, $detail['identity_name']);
        $this->get('/admin/residents/'.$one->id)->assertForbidden();
        $this->get('/admin/cameras/'.$this->monitorCamera->id)->assertForbidden();
    }

    public function test_overlapping_stays_and_residents_from_other_rooms_are_not_candidates(): void
    {
        $this->perform('acknowledge');
        $resident = $this->resident();
        $other = $this->room();
        ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $this->monitorPeriod->room_id, 'started_at' => $this->eventTime->subDay()]);
        ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $other->id,
            'started_at' => $this->eventTime->subHours(2), 'ended_at' => $this->eventTime->addMinute()]);
        $outsider = $this->resident();
        ElderlyStay::create(['elderly_id' => $outsider->id, 'room_id' => $other->id, 'started_at' => $this->eventTime->subDay()]);
        $this->getJson($this->path('resident-options'))->assertOk()->assertJsonPath('data', []);
        foreach ([$resident, $outsider] as $invalid) {
            $this->postJson($this->path('verify'), $this->command('verify', ['identity_mode' => 'known', 'elderly_id' => $invalid->id]))->assertUnprocessable();
        }
        $this->perform('verify');
        $this->perform('resolve');
        $this->assertNull($this->incident->fresh()->elderly_id);
    }

    public static function stayBoundaries(): array
    {
        return [['starts_at_event', true], ['ends_at_event', false], ['starts_after', false], ['ends_after', true]];
    }

    #[DataProvider('stayBoundaries')]
    public function test_candidate_stay_boundaries_are_half_open(string $case, bool $eligible): void
    {
        $this->perform('acknowledge');
        $resident = $this->resident();
        $time = $this->eventTime->startOfSecond();
        $this->incident->aiDetection->update(['occurred_at' => $time]);
        ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $this->monitorPeriod->room_id,
            'started_at' => match ($case) {
                'starts_at_event' => $time, 'starts_after' => $time->addSecond(), default => $time->subDay()
            },
            'ended_at' => match ($case) {
                'ends_at_event' => $time, 'ends_after' => $time->addSecond(), default => null
            }]);
        $rows = $this->getJson($this->path('resident-options'))->assertOk()->json('data');
        $this->assertSame($eligible ? [$resident->id] : [], array_column($rows, 'id'));
    }

    public function test_vip_single_resident_is_not_selected_or_verified_automatically(): void
    {
        $room = $this->room($this->vip);
        $camera = $this->camera($room);
        $period = $this->periodFor($camera);
        $this->incident->aiDetection->update(['camera_id' => $camera->id, 'camera_period_id' => $period->id]);
        $resident = $this->resident();
        ElderlyStay::create(['elderly_id' => $resident->id, 'room_id' => $room->id, 'started_at' => $this->eventTime->subDay()]);
        $this->perform('acknowledge');
        $this->getJson($this->path('resident-options'))->assertOk()->assertJsonPath('data.0.id', $resident->id);
        $this->assertNull($this->incident->fresh()->elderly_id);
        $this->assertNull($this->incident->fresh()->verified_at);
        $this->perform('verify');
        $this->assertNull($this->incident->fresh()->elderly_id);
    }

    public function test_all_time_index_pagination_includes_old_terminal_and_does_not_multiply_actions(): void
    {
        $this->perform('acknowledge');
        $this->perform('verify');
        $this->perform('resolve');
        for ($i = 0; $i < 16; $i++) {
            $this->fall('false_alarm', changes: ['occurred_at' => $this->eventTime->subDays(200)]);
        }
        $props = $this->get('/monitoring/incidents?per_page=15')->assertOk()->viewData('page')['props'];
        $this->assertSame(17, $props['records']['total']);
        $this->assertCount(15, $props['records']['data']);
        $next = $this->get($props['records']['next_page_url'])->assertOk()->viewData('page')['props'];
        $this->assertCount(2, $next['records']['data']);
        $this->assertSame(17, $next['records']['total']);
        $this->assertSame(1, $this->listing()['records']['total']);
    }

    public static function unavailableContext(): array
    {
        return [['legacy'], ['outside_period'], ['future']];
    }

    #[DataProvider('unavailableContext')]
    public function test_unknown_context_never_falls_back_to_current_room_but_can_be_handled(string $case): void
    {
        if ($case === 'legacy') {
            $this->incident->aiDetection->update(['camera_period_id' => null]);
        } elseif ($case === 'future') {
            $this->monitorPeriod->update(['closed_at' => null]);
            $this->incident->aiDetection->update(['occurred_at' => now()->addHour()]);
        } else {
            $this->monitorPeriod->update(['closed_at' => $this->eventTime]);
        }
        $this->perform('acknowledge');
        $this->getJson($this->path('resident-options'))->assertOk()->assertJsonPath('data', []);
        $this->assertNull($this->detail()['incident']['context']['room_code']);
        $this->perform('verify');
        $this->perform('resolve');
    }

    public function test_existing_verified_legacy_identity_is_preserved_and_wrong_parent_is_not_handled(): void
    {
        $resident = $this->resident();
        $known = $this->fall('resolved', resident: $resident, changes: ['camera_period_id' => null])->fallIncident;
        $props = $this->get($this->path(incident: $known))->assertOk()->viewData('page')['props']['incident'];
        $this->assertSame($resident->name, $props['identity_name']);
        $this->assertNull($props['context']['room_code']);
        $this->incident->aiDetection->update(['event_type' => 'gait']);
        $before = $this->monitoringSnapshot();
        $this->get($this->path())->assertNotFound();
        $this->postJson($this->path('acknowledge'), $this->command('acknowledge'))->assertNotFound();
        $this->assertSame($before, $this->monitoringSnapshot());
    }

    public function test_dashboard_links_use_incident_uuid_and_counts_remain_consistent_after_manual_actions(): void
    {
        $row = collect($this->listing()['records']['data'])->firstWhere('id', $this->incident->ai_detection_id);
        $this->assertSame($this->path(), $row['fall']['detail_url']);
        $this->assertNotSame($this->incident->id, $row['id']);
        $this->perform('acknowledge');
        $this->perform('verify');
        $this->assertSame(1, $this->listing(path: '/monitoring/open-incidents')['records']['total']);
        $this->perform('resolve');
        $this->assertSame(0, $this->listing(path: '/monitoring/open-incidents')['records']['total']);
        $this->assertSame(1, $this->listing()['records']['total']);
        $all = $this->get('/monitoring/incidents?status=resolved')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $all['records']['total']);
        $this->assertCount(1, $all['records']['data']);
    }

    public function test_show_and_filter_errors_do_not_echo_sensitive_query_parameters(): void
    {
        foreach ([$this->path(), '/monitoring/incidents'] as $path) {
            $response = $this->get($path.'?notes=PRIVATE-QUERY')->assertRedirect();
            $this->assertStringNotContainsString('PRIVATE-QUERY', $response->headers->get('Location'));
        }
        $this->get('/monitoring/incidents/invalid?notes=PRIVATE-QUERY')->assertRedirect();
        $this->get('/monitoring/incidents/invalid')->assertNotFound()->assertDontSee('SQLSTATE');
    }
}
