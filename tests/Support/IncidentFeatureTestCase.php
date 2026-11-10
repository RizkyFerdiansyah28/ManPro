<?php

namespace Tests\Support;

use App\Models\FallIncident;
use App\Models\User;
use App\Support\IncidentVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

abstract class IncidentFeatureTestCase extends MonitoringFeatureTestCase
{
    protected User $nurse;

    protected FallIncident $incident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nurse = $this->account('perawat');
        $this->incident = $this->event()->fallIncident;
        $this->signIn($this->nurse);
    }

    protected function signIn(User $user): void
    {
        // Pergantian fixture bukan logout aplikasi yang sengaja merotasi remember_token.
        Auth::forgetGuards();
        session()->flush();
        $this->actingAs($user->fresh());
    }

    protected function assertOnlySessionRevocationChanged(array $before, User $user): void
    {
        $after = $this->monitoringSnapshot();
        $index = array_search($user->id, array_column($before['users'], 'id'), true);
        $this->assertNotFalse($index);
        $token = $after['users'][$index]['remember_token'];
        $this->assertNotSame($before['users'][$index]['remember_token'], $token);
        $this->assertSame(60, strlen($token));
        $before['users'][$index]['remember_token'] = $token;
        $this->assertSame($before, $after);
    }

    protected function command(string $action, array $overrides = [], ?FallIncident $incident = null): array
    {
        return ['request_id' => (string) Str::uuid(), 'expected_version' => IncidentVersion::of(($incident ?? $this->incident)->fresh()),
            'confirm' => true, 'notes' => 'Catatan penanganan fixture',
            ...($action === 'verify' ? ['identity_mode' => 'unknown'] : []), ...$overrides];
    }

    protected function path(string $action = '', ?FallIncident $incident = null): string
    {
        return '/monitoring/incidents/'.($incident ?? $this->incident)->id.($action ? '/'.str_replace('_', '-', $action) : '');
    }

    protected function perform(string $action, array $overrides = []): void
    {
        $this->postJson($this->path($action), $this->command($action, $overrides))->assertOk();
    }

    protected function detail(): array
    {
        return $this->get($this->path())->assertOk()->viewData('page')['props'];
    }
}
