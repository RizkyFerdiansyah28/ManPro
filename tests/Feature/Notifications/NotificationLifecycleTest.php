<?php

namespace Tests\Feature\Notifications;

use App\Services\IncidentService;
use App\Support\IncidentVersion;
use App\Support\StaffNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NotificationFeatureTestCase;

class NotificationLifecycleTest extends NotificationFeatureTestCase
{
    public function test_read_and_mark_all_only_update_own_read_at_once_and_preserve_other_fields(): void
    {
        $one = $this->notice();
        $two = $this->notice(incident: $this->fall());
        $other = $this->notice($this->admin);
        $legacy = $this->notice(changes: ['type' => 'fixture.legacy', 'fall_incident_id' => null]);
        $before = $this->snapshot();
        $this->postJson('/notifications/'.$one->id.'/read')->assertOk()->assertJsonPath('changed', 1);
        $readAt = DB::table('notifications')->where('id', $one->id)->value('read_at');
        $this->postJson('/notifications/'.$one->id.'/read')->assertOk()->assertJsonPath('changed', 0);
        $this->postJson('/notifications/read-all')->assertUnprocessable();
        $this->postJson('/notifications/read-all', ['confirm' => true])->assertOk()->assertJsonPath('changed', 1);
        $this->postJson('/notifications/read-all', ['confirm' => true])->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame($readAt, DB::table('notifications')->where('id', $one->id)->value('read_at'));
        $this->assertNull($other->fresh()->read_at);
        $this->assertNull($legacy->fresh()->read_at);
        $after = $this->snapshot();
        foreach ($before['notifications'] as &$row) {
            if (in_array($row['id'], [$one->id, $two->id])) {
                $updated = collect($after['notifications'])->firstWhere('id', $row['id']);
                $this->assertNotNull($updated['read_at']);
                $row['read_at'] = $updated['read_at'];
            }
        }
        unset($row);
        $this->assertSame($before, $after);
    }

    public function test_filters_pagination_readonly_gets_and_summary_keep_pending_separate_from_unread(): void
    {
        for ($i = 0; $i < 17; $i++) {
            $this->notice(incident: $this->fall());
        }
        $before = $this->snapshot();
        $props = $this->get('/notifications?per_page=15')->assertOk()->viewData('page')['props'];
        $this->assertSame(17, $props['records']['total']);
        $this->assertCount(15, $props['records']['data']);
        $this->assertCount(2, $this->get($props['records']['next_page_url'])->assertOk()->viewData('page')['props']['records']['data']);
        $this->getJson('/notifications/summary')->assertOk()->assertJsonPath('unread_count', 17)->assertJsonPath('pending_count', 18);
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/notifications/read-all', ['confirm' => true])->assertOk();
        $this->getJson('/notifications/summary')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('pending_count', 18);
        $this->assertSame(0, $this->get('/notifications?state=unread')->assertOk()->viewData('page')['props']['records']['total']);
        $this->assertSame(17, $this->get('/notifications?state=read')->assertOk()->viewData('page')['props']['records']['total']);
        $this->post('/notifications/read-all', ['confirm' => true, 'state' => 'unread', 'page' => 2, 'per_page' => 30])
            ->assertStatus(303)->assertRedirect('/notifications?state=unread&page=2&per_page=30');
    }

    public function test_props_never_expose_payload_pii_notes_or_private_gateway_and_malformed_data_is_safe(): void
    {
        $this->deliver();
        $this->notice(incident: $this->fall(), changes: ['data' => ['schema_version' => 1, 'message' => '<script>PRIVATE-HTML</script>', 'secret' => 'PRIVATE-DATA']]);
        $corrupt = $this->notice(incident: $this->fall());
        DB::table('notifications')->where('id', $corrupt->id)->update(['data' => '{invalid PRIVATE-JSON']);
        $before = $this->snapshot();
        $response = $this->get('/notifications')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $props = $response->viewData('page')['props'];
        $encoded = json_encode($props);
        foreach (['PRIVATE-STAFF-NOTE', 'PRIVATE-GATEWAY-SENTINEL', 'PRIVATE-HTML', 'PRIVATE-DATA', 'PRIVATE-JSON',
            'source_reference', 'metadata', 'notifiable_id', 'notifiable_type', 'verified_by', 'payload', 'birth_date', 'monthly_price'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame(2, collect($props['records']['data'])->whereNull('incident')->count());
        $this->assertFalse($response->headers->has('X-Inertia-Devtools-Id'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_incident_transitions_refresh_context_but_never_reset_notification_or_create_new_kind(): void
    {
        $this->deliver();
        $notices = DB::table('notifications')->orderBy('id')->get()->all();
        foreach (['acknowledge', 'verify', 'resolve'] as $action) {
            app(IncidentService::class)->act($this->nurse, $this->incident->id, $action, ['request_id' => (string) Str::uuid(),
                'expected_version' => IncidentVersion::of($this->incident->fresh()), 'confirm' => true, 'notes' => 'Fixture penanganan',
                ...($action === 'verify' ? ['identity_mode' => 'unknown'] : [])]);
        }
        $this->assertEquals($notices, DB::table('notifications')->orderBy('id')->get()->all());
        $row = $this->get('/notifications')->assertOk()->viewData('page')['props']['records']['data'][0];
        $this->assertSame('resolved', $row['incident']['status']);
        $this->assertTrue($row['incident']['is_verified']);
        $this->getJson('/notifications/summary')->assertOk()->assertJsonPath('pending_count', 0)->assertJsonPath('unread_count', 1);
        $this->assertSame([StaffNotification::KIND], DB::table('notifications')->distinct()->pluck('type')->all());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->family->id)->count());
    }

    public function test_inbox_failure_is_generic_without_breaking_existing_safety_monitoring(): void
    {
        DB::statement('ALTER TABLE notifications RENAME TO fixture_unavailable');
        try {
            $this->getJson('/notifications/summary')->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('fixture_unavailable');
            $this->get('/notifications')->assertStatus(503)->assertDontSee('SQLSTATE');
            $this->get('/monitoring/open-incidents')->assertOk();
            $this->get('/monitoring/incidents/'.$this->incident->id)->assertOk();
        } finally {
            DB::statement('ALTER TABLE fixture_unavailable RENAME TO notifications');
        }
    }
}
