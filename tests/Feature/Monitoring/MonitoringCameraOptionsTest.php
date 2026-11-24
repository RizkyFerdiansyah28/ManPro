<?php

namespace Tests\Feature\Monitoring;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CameraFeatureTestCase;

class MonitoringCameraOptionsTest extends CameraFeatureTestCase
{
    public static function staff(): array
    {
        return [['admin', '/admin'], ['perawat', '/perawat']];
    }

    #[DataProvider('staff')]
    public function test_dashboard_exposes_only_registered_camera_identity_and_its_current_room(string $role, string $home): void
    {
        $sharedRoom = $this->room($this->basic, ['code' => 'D-02', 'name' => 'Kamar bersama']);
        $privateRoom = $this->room($this->vip, ['code' => 'D-01', 'name' => 'Kamar privat']);
        $sharedCamera = $this->camera($sharedRoom, ['name' => 'Kamera Z']);
        $privateCamera = $this->camera($privateRoom, ['name' => 'Kamera A', 'is_active' => true,
            'source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        $this->room($this->shared, ['code' => 'D-03', 'name' => 'Kamar tanpa kamera']);
        $this->actingAs($role === 'admin' ? $this->admin : $this->account($role));
        $before = $this->historySnapshot();

        $response = $this->get($home.'?range=7d')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $props = $response->viewData('page')['props'];
        $this->assertSame([
            ['id' => $privateCamera->id, 'name' => 'Kamera A', 'room' => ['id' => $privateRoom->id, 'code' => 'D-01', 'name' => 'Kamar privat']],
            ['id' => $sharedCamera->id, 'name' => 'Kamera Z', 'room' => ['id' => $sharedRoom->id, 'code' => 'D-02', 'name' => 'Kamar bersama']],
        ], $props['camera_options']);
        $this->assertSame(['registered' => 2, 'active' => 1, 'inactive' => 1], $props['cameras']);
        $this->assertSame([], $props['recent']);
        $this->assertSame($before, $this->historySnapshot());
        $this->assertDatabaseCount('camera_operating_periods', 0);
    }

    public function test_dashboard_returns_empty_options_when_no_camera_is_registered(): void
    {
        $this->room();
        $props = $this->get('/admin')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['camera_options']);
        $this->assertSame(['registered' => 0, 'active' => 0, 'inactive' => 0], $props['cameras']);
    }
}
