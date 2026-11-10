<?php

namespace Tests\Feature\Incidents;

use App\Models\FallIncidentAction;
use App\Support\IncidentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IncidentFeatureTestCase;

class IncidentWorkflowTest extends IncidentFeatureTestCase
{
    public static function transitions(): array
    {
        $cases = [];
        foreach (['pending', 'acknowledged', 'verified', 'resolved', 'false_alarm'] as $state) {
            foreach (['acknowledge', 'verify', 'resolve', 'false_alarm'] as $action) {
                $cases[$state.'-'.$action] = [$state, $action,
                    in_array($state.'-'.$action, ['pending-acknowledge', 'acknowledged-verify', 'acknowledged-false_alarm', 'verified-resolve'], true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_only_approved_transitions_are_allowed(string $state, string $action, bool $allowed): void
    {
        $incident = $this->fall($state === 'verified' ? 'acknowledged' : $state, $state === 'verified')->fallIncident;
        $before = $this->monitoringSnapshot();
        $this->postJson($this->path($action, $incident), $this->command($action, incident: $incident))
            ->assertStatus($allowed ? 200 : 409);
        $this->assertDatabaseCount('fall_incident_actions', $allowed ? 1 : 0);
        if (! $allowed) {
            $this->assertSame($before, $this->monitoringSnapshot());
        }
    }

    public function test_teamwork_preserves_first_responder_and_records_each_actual_actor_and_unknown_identity(): void
    {
        $aiBefore = $this->incident->aiDetection->getRawOriginal();
        $before = $this->monitoringSnapshot();
        $this->perform('acknowledge', ['notes' => null]);
        $ack = $this->incident->fresh()->getRawOriginal('acknowledged_at');
        $second = $this->account('perawat');
        $this->nurse->forceFill(['is_active' => false])->save();
        $this->assertFalse($this->nurse->fresh()->is_active);
        $this->signIn($second);
        $this->perform('verify');
        $third = $this->account('perawat');
        $this->signIn($third);
        $this->perform('resolve');
        $incident = $this->incident->fresh();
        $this->assertSame('resolved', $incident->status);
        $this->assertNull($incident->elderly_id);
        $this->assertSame($this->nurse->id, $incident->responding_caregiver_id);
        $this->assertSame($ack, $incident->getRawOriginal('acknowledged_at'));
        $this->assertSame($second->id, $incident->verified_by);
        $this->assertSame('PRIVATE-NOTES-SENTINEL', $incident->notes);
        $entries = $incident->actions()->get();
        $this->assertSame([1, 2, 3], $entries->pluck('sequence')->all());
        $this->assertSame([$this->nurse->id, $second->id, $third->id], $entries->pluck('actor_id')->all());
        $this->assertTrue($incident->resolved_at->greaterThanOrEqualTo($incident->verified_at));
        $this->assertSame($aiBefore, $incident->aiDetection->getRawOriginal());
        foreach (['rooms', 'service_classes', 'cameras', 'camera_operating_periods', 'elderly_stays', 'service_enrollments',
            'elderlies', 'ai_detections', 'gait_observations', 'invoices', 'payments', 'notifications'] as $table) {
            $this->assertSame($before[$table], $this->monitoringSnapshot()[$table], $table);
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_exact_retry_is_noop_even_after_another_caregiver_advances_state(): void
    {
        $payload = $this->command('acknowledge');
        $this->postJson($this->path('acknowledge'), $payload)->assertOk()->assertJsonPath('duplicate', false);
        $this->signIn($this->account('perawat'));
        $this->perform('verify');
        $this->perform('resolve');
        $this->signIn($this->nurse);
        $before = $this->incident->fresh()->getRawOriginal();
        $this->postJson($this->path('acknowledge'), $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($before, $this->incident->fresh()->getRawOriginal());
        $this->assertDatabaseCount('fall_incident_actions', 3);
        $this->postJson($this->path('acknowledge'), [...$payload, 'notes' => 'Intent berbeda'])->assertStatus(409);
        $other = $this->event()->fallIncident;
        $this->postJson($this->path('acknowledge', $other), $payload)->assertStatus(409);
        $this->assertSame('pending', $other->fresh()->status);
        $this->nurse->forceFill(['is_active' => false])->save();
        $this->signIn($this->nurse);
        $this->postJson($this->path('acknowledge'), $payload)->assertRedirect('/login');
    }

    public function test_new_key_stale_version_and_same_second_field_changes_conflict(): void
    {
        $payload = $this->command('acknowledge');
        $this->perform('acknowledge');
        $this->postJson($this->path('acknowledge'), $payload)->assertStatus(409);
        $version = IncidentVersion::of($this->incident->fresh());
        DB::table('fall_incidents')->where('id', $this->incident->id)->update(['notes' => 'Perubahan tanpa updated_at']);
        $this->assertNotSame($version, IncidentVersion::of($this->incident->fresh()));
        $this->postJson($this->path('verify'), $this->command('verify', ['expected_version' => $version]))->assertStatus(409);
    }

    public function test_false_alarm_closes_without_identity_or_ai_rewrite(): void
    {
        $before = $this->incident->aiDetection->getRawOriginal();
        $this->perform('acknowledge');
        $this->perform('false_alarm');
        $incident = $this->incident->fresh();
        $this->assertSame('false_alarm', $incident->status);
        $this->assertNull($incident->elderly_id);
        $this->assertNull($incident->verified_by);
        $this->assertNull($incident->verified_at);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame($before, $incident->aiDetection->getRawOriginal());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_journal_failure_rolls_back_incident_and_hides_private_sql_bindings(): void
    {
        $before = $this->monitoringSnapshot();
        $event = 'eloquent.creating: '.FallIncidentAction::class;
        Event::listen($event, function () {
            DB::statement("INSERT INTO fall_incident_actions (id) VALUES ('not-a-uuid')");
        });
        try {
            $response = $this->postJson($this->path('acknowledge'), $this->command('acknowledge', ['notes' => 'PRIVATE-FAILURE-NOTE']));
        } finally {
            Event::forget($event);
        }
        $response->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('PRIVATE-FAILURE-NOTE');
        $this->assertSame($before, $this->monitoringSnapshot());
        $this->assertDatabaseCount('fall_incident_actions', 0);
    }

    public function test_server_clock_before_previous_handling_fails_closed(): void
    {
        $this->incident->update(['status' => 'acknowledged', 'responding_caregiver_id' => $this->nurse->id,
            'acknowledged_at' => now()->addDay()]);
        $before = $this->monitoringSnapshot();
        $this->postJson($this->path('verify'), $this->command('verify'))->assertStatus(409);
        $this->assertSame($before, $this->monitoringSnapshot());
    }

    public function test_browser_redirects_and_detail_version_match_service_without_flashing_notes(): void
    {
        $props = $this->detail();
        $this->assertSame(IncidentVersion::of($this->incident->fresh()), $props['incident']['version']);
        $this->post($this->path('acknowledge'), $this->command('acknowledge'))->assertStatus(303)->assertRedirect($this->path());
        $this->post($this->path('resolve'), $this->command('resolve'))->assertStatus(303)->assertSessionHasErrors('incident');
        $this->assertNull(session()->getOldInput('notes'));
        $props = $this->detail();
        $this->assertCount(1, $props['actions']['data']);
        $this->assertSame(['verify', 'false_alarm'], $props['incident']['allowed_actions']);
    }
}
