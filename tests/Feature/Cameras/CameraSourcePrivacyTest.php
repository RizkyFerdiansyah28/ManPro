<?php

namespace Tests\Feature\Cameras;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CameraFeatureTestCase;

class CameraSourcePrivacyTest extends CameraFeatureTestCase
{
    public static function rtspSources(): array
    {
        return [[self::SOURCE], ['rtsps://camera.example.test/live'], ['rtsp://[::1]:554/live'], ['rtsp://127.0.0.1/live']];
    }

    #[DataProvider('rtspSources')]
    public function test_valid_rtsp_reference_is_encrypted_never_returned_and_never_connected(string $source): void
    {
        Http::preventStrayRequests();
        $room = $this->room();
        $this->post('/admin/cameras', $this->cameraPayload($room, ['source_type' => 'rtsp', 'source_reference' => $source]))->assertSessionHasNoErrors();
        $camera = $room->camera()->sole();
        $this->assertSame($source, $camera->source_reference);
        $ciphertext = $camera->getRawOriginal('source_reference');
        $this->assertNotSame($source, $ciphertext);
        $this->assertArrayNotHasKey('source_reference', $camera->toArray());
        foreach (['/admin/cameras', "/admin/cameras/{$camera->id}", "/admin/cameras/{$camera->id}/edit"] as $path) {
            $response = $this->get($path)->assertOk()->assertDontSee($source, false)->assertDontSee($ciphertext, false);
            if ($path !== '/admin/cameras') {
                $response->assertInertia(fn (Assert $page) => $page
                    ->where('camera.has_source_reference', true)->missing('camera.source_reference')->missing('camera.credentials')->missing('camera.stream_url'));
            }
        }
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertTrue($camera->fresh()->is_active);
        Http::assertNothingSent();
    }

    public static function invalidSources(): array
    {
        return [['https://camera.example.test/live'], ['http://camera.example.test/live'], ['javascript:alert(1)'], ['file:///private/video'],
            ['data:video/mp4,fixture'], ['rtsp:///missing-host'], ['rtsp://camera.example.test:70000/live'],
            ["rtsp://camera.example.test/live\r\nfixture-secret"], ['rtsp://camera.example.test/a b'], ['rtsp://camera.example.test/live#fragment'],
            ['rtsp://camera.example.test/%ZZ'], ['rtsp://camera.example.test/live%0d%0afixture-secret'],
            [' rtsp://camera.example.test/live '], [true], [['fixture-secret']]];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_errors_do_not_echo_or_flash_private_value(mixed $source): void
    {
        $room = $this->room();
        $this->post('/admin/cameras', $this->cameraPayload($room, ['source_type' => 'rtsp', 'source_reference' => $source]))
            ->assertSessionHasErrors('source_reference')->assertSessionMissing('_old_input.source_reference');
        $this->assertDatabaseCount('cameras', 0);
        $response = $this->postJson('/admin/cameras', $this->cameraPayload($room, ['source_type' => 'rtsp', 'source_reference' => $source]))
            ->assertUnprocessable()->assertJsonValidationErrors('source_reference');
        if (is_string($source)) {
            $response->assertDontSee($source, false);
        }
    }

    public function test_source_is_excluded_from_old_input_even_when_another_field_fails(): void
    {
        $room = $this->room();
        $this->post('/admin/cameras', $this->cameraPayload($room, ['name' => '', 'source_type' => 'rtsp', 'source_reference' => self::SOURCE]))
            ->assertSessionHasErrors('name')->assertSessionMissing('_old_input.source_reference');
        $this->assertDatabaseCount('cameras', 0);
    }

    public function test_private_file_reference_requires_existing_file_and_does_not_upload_or_serve_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('videos/fixture.mp4', 'fixture only; no playback claim');
        $room = $this->room();
        $this->post('/admin/cameras', $this->cameraPayload($room, ['source_type' => 'uploaded_video', 'source_reference' => 'videos/fixture.mp4']))->assertSessionHasNoErrors();
        $camera = $room->camera()->sole();
        $this->assertSame('videos/fixture.mp4', $camera->source_reference);
        $this->get("/admin/cameras/{$camera->id}")->assertOk()->assertDontSee('videos/fixture.mp4', false);
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        Storage::disk('local')->delete('videos/fixture.mp4');
        $this->post("/admin/cameras/{$camera->id}/deactivate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasErrors('source_reference');
        $this->assertFalse($camera->fresh()->is_active);
    }

    public static function invalidFiles(): array
    {
        return [['../outside.mp4'], ['/absolute.mp4'], ['C:\\private\\file.mp4'], ['\\\\server\\file.mp4'], ['https://example.test/video.mp4'],
            ['missing.mp4'], ['videos/./fixture.mp4'], ['videos/../fixture.mp4'], ['videos']];
    }

    #[DataProvider('invalidFiles')]
    public function test_unsafe_missing_or_non_file_private_references_are_rejected(string $source): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('videos/fixture.mp4', 'fixture');
        $room = $this->room();
        $this->post('/admin/cameras', $this->cameraPayload($room, ['source_type' => 'uploaded_video', 'source_reference' => $source]))
            ->assertSessionHasErrors('source_reference')->assertSessionMissing('_old_input.source_reference');
        $this->assertDatabaseCount('cameras', 0);
    }

    public function test_sources_can_be_kept_replaced_or_cleared_only_explicitly_while_inactive(): void
    {
        $camera = $this->camera(changes: ['source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        $before = $camera->getRawOriginal('source_reference');
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['name' => 'Metadata saja']))->assertSessionHasNoErrors();
        $this->assertSame($before, $camera->fresh()->getRawOriginal('source_reference'));
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_reference' => 'rtsps://new.example.test/live']))->assertSessionHasErrors('source_reference');
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_action' => 'replace', 'source_reference' => 'rtsps://new.example.test/live']))->assertSessionHasNoErrors();
        $this->assertSame('rtsps://new.example.test/live', $camera->fresh()->source_reference);
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $active = $camera->fresh()->getAttributes();
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_action' => 'clear']))->assertSessionHasErrors('source_action');
        $this->assertSame($active, $camera->fresh()->getAttributes());
        $this->post("/admin/cameras/{$camera->id}/deactivate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_action' => 'clear']))->assertSessionHasNoErrors();
        $this->assertNull($camera->fresh()->source_reference);
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasErrors('source_reference');
    }

    public function test_history_freezes_type_and_room_but_allows_explicit_future_reference_rotation(): void
    {
        $camera = $this->camera(changes: ['source_type' => 'rtsp', 'source_reference' => self::SOURCE]);
        $event = $camera->aiDetections()->create(['external_event_id' => 'private-history-fixture', 'event_type' => 'fall', 'occurred_at' => now(), 'received_at' => now(), 'metadata' => ['fixture' => 'private-metadata']]);
        $event->fallIncident()->create([]);
        $before = $this->historySnapshot();
        unset($before['cameras']);
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_type' => 'webcam', 'source_action' => 'clear']))->assertSessionHasErrors('source_type');
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, ['source_action' => 'replace', 'source_reference' => 'rtsp://rotated.example.test/live']))->assertSessionHasNoErrors();
        $after = $this->historySnapshot();
        unset($after['cameras']);
        $this->assertSame($before, $after);
        $this->get("/admin/cameras/{$camera->id}")->assertOk()->assertDontSee('private-metadata')->assertDontSee('private-history-fixture');
    }

    public function test_webcam_rejects_reference_and_unreadable_ciphertext_can_be_repaired_without_leaking(): void
    {
        $room = $this->room();
        $this->post('/admin/cameras', $this->cameraPayload($room, ['source_reference' => self::SOURCE]))->assertSessionHasErrors('source_reference');
        $camera = $this->camera($room, ['source_type' => 'rtsp']);
        DB::table('cameras')->where('id', $camera->id)->update(['source_reference' => 'fixture-invalid-ciphertext']);
        $this->get("/admin/cameras/{$camera->id}")->assertOk()->assertDontSee('fixture-invalid-ciphertext');
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasErrors('source_reference');
        $this->assertFalse($camera->fresh()->is_active);
        $this->patch("/admin/cameras/{$camera->id}", $this->cameraUpdate($camera, [
            'source_action' => 'replace', 'source_reference' => self::SOURCE,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(self::SOURCE, $camera->fresh()->source_reference);
        $this->assertNotSame(self::SOURCE, $camera->fresh()->getRawOriginal('source_reference'));
        $this->post("/admin/cameras/{$camera->id}/activate", $this->statusPayload($camera))->assertSessionHasNoErrors();
        $this->assertTrue($camera->fresh()->is_active);
        $this->get("/admin/cameras/{$camera->id}")->assertOk()->assertDontSee(self::SOURCE, false)->assertDontSee('fixture-invalid-ciphertext');
    }
}
