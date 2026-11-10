<?php

namespace Tests\Feature\Incidents;

use App\Models\AiDetection;
use App\Services\IncidentService;
use App\Support\IncidentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiGatewayFeatureTestCase;

class IncidentGatewayRetentionTest extends AiGatewayFeatureTestCase
{
    public static function states(): array
    {
        return [['acknowledged'], ['verified'], ['resolved'], ['false_alarm']];
    }

    #[DataProvider('states')]
    public function test_gateway_retry_preserves_real_human_workflow_and_journal(string $state): void
    {
        $payload = $this->payload();
        $this->sendEvent($payload)->assertCreated();
        $incident = AiDetection::sole()->fallIncident;
        $nurse = $this->account('perawat');
        $service = app(IncidentService::class);
        $act = function ($action) use ($service, $nurse, $incident) {
            return $service->act($nurse, $incident->id, $action, ['request_id' => (string) Str::uuid(),
                'expected_version' => IncidentVersion::of($incident->fresh()), 'confirm' => true,
                'notes' => 'Catatan fixture', ...($action === 'verify' ? ['identity_mode' => 'unknown'] : [])]);
        };
        $act('acknowledge');
        if (in_array($state, ['verified', 'resolved'], true)) {
            $act('verify');
        }
        if (in_array($state, ['resolved', 'false_alarm'], true)) {
            $act($state === 'resolved' ? 'resolve' : 'false_alarm');
        }
        $before = $this->historySnapshot();
        $journal = DB::table('fall_incident_actions')->orderBy('sequence')->get()->all();
        $this->sendEvent($payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($before, $this->historySnapshot());
        $this->assertEquals($journal, DB::table('fall_incident_actions')->orderBy('sequence')->get()->all());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }
}
