<?php

namespace Tests\Feature\FamilyPortal;

use App\Models\AiDetection;
use App\Models\Camera;
use App\Models\FallIncident;
use App\Models\GaitObservation;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FamilyPortalFeatureTestCase;

class FamilyPortalPrivacyTest extends FamilyPortalFeatureTestCase
{
    public function test_json_errors_never_include_debug_trace_or_untrusted_query(): void
    {
        config(['app.debug' => true]);
        $foreign = $this->resident($this->account('keluarga'));
        $before = $this->snapshot();
        $this->getJson('/keluarga/residents/'.$foreign->id.'?notes=PRIVATE-ERROR')->assertUnprocessable()->assertDontSee('PRIVATE-ERROR');
        $this->getJson('/keluarga/residents/'.$foreign->id)->assertNotFound()
            ->assertExactJson(['message' => 'Halaman tidak ditemukan.']);
        $this->signIn($this->admin);
        $this->getJson('/keluarga?notes=PRIVATE-ERROR')->assertForbidden()->assertExactJson(['message' => 'Akses dibatasi.']);
        $this->signIn($this->family);
        $this->postJson('/keluarga', [])->assertStatus(405)
            ->assertExactJson(['message' => 'Metode permintaan tidak diizinkan.'])->assertHeader('Allow');
        $this->post('/keluarga?notes=PRIVATE-HTML', [])->assertStatus(405)->assertHeader('Allow')
            ->assertDontSee('PRIVATE-HTML')->assertInertia(fn ($p) => $p->component('Error')->where('status', 405));
        $this->assertSame($before, $this->snapshot());
    }

    public static function billing(): array
    {
        return [['absent'], ['grace'], ['overdue'], ['pending'], ['verified'], ['rejected'], ['paid'], ['cancelled']];
    }

    #[DataProvider('billing')]
    public function test_financial_and_safety_records_never_leak_or_gate_current_basics(string $case): void
    {
        $e = $this->resident();
        $room = $this->room();
        $en = $this->enrollment($e, $room);
        $this->stay($e, $room);
        if ($case !== 'absent') {
            $due = $case === 'grace' ? $this->time->subDays(2) : $this->time->subDays(30);
            $invoice = Invoice::create(['number' => 'PRIVATE-INVOICE', 'elderly_id' => $e->id, 'service_enrollment_id' => $en->id,
                'billing_month' => $due->startOfMonth()->toDateString(), 'amount' => '123.45', 'due_date' => $due->toDateString(),
                'grace_period_ends_on' => $due->addDays(3)->toDateString(),
                'status' => in_array($case, ['paid', 'cancelled']) ? $case : 'unpaid', 'paid_at' => $case === 'paid' ? $this->time : null]);
            if (in_array($case, ['pending', 'verified', 'rejected'])) {
                Payment::create(['invoice_id' => $invoice->id, 'submitted_by' => $this->family->id, 'amount' => '123.45',
                    'proof_path' => 'PRIVATE-PROOF', 'submitted_at' => $this->time->subDay(), 'status' => $case,
                    'verified_by' => $case === 'pending' ? null : $this->admin->id, 'verified_at' => $case === 'pending' ? null : $this->time,
                    'verification_notes' => 'PRIVATE-REVIEW']);
            }
        }
        $camera = Camera::create(['room_id' => $room->id, 'name' => 'PRIVATE-CAMERA',
            'source_type' => 'rtsp', 'source_reference' => 'rtsp://PRIVATE-SOURCE:secret@example.test/live', 'is_active' => false]);
        $nurse = $this->account('perawat');
        foreach ([['pending', false], ['acknowledged', false], ['acknowledged', true], ['resolved', true], ['false_alarm', false]] as [$state, $verified]) {
            $event = AiDetection::create(['camera_id' => $camera->id, 'external_event_id' => (string) Str::uuid(), 'event_type' => 'fall',
                'occurred_at' => $this->time->subHour(), 'metadata' => ['sentinel' => 'PRIVATE-METADATA']]);
            $f = FallIncident::create(['ai_detection_id' => $event->id, 'status' => $state, 'elderly_id' => $verified ? $e->id : null,
                'responding_caregiver_id' => $state === 'pending' ? null : $nurse->id,
                'acknowledged_at' => $state === 'pending' ? null : $this->time->subMinutes(3),
                'verified_by' => $verified ? $nurse->id : null, 'verified_at' => $verified ? $this->time->subMinutes(2) : null,
                'resolved_at' => in_array($state, ['resolved', 'false_alarm']) ? $this->time->subMinute() : null, 'notes' => 'PRIVATE-NOTES']);
            Notification::create(['type' => 'fixture.family.legacy', 'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $this->family->id, 'fall_incident_id' => $f->id, 'data' => ['message' => 'PRIVATE-NOTICE']]);
        }
        foreach (['walking_normal', 'walking_abnormal', 'no_walking_detected'] as $category) {
            $event = AiDetection::create(['camera_id' => $camera->id, 'external_event_id' => (string) Str::uuid(), 'event_type' => 'gait',
                'occurred_at' => $this->time->subHour()]);
            GaitObservation::create(['ai_detection_id' => $event->id, 'category' => $category]);
        }
        $foreign = $this->resident($this->account('keluarga'), ['name' => 'PRIVATE-FOREIGN']);
        $before = $this->snapshot();
        foreach (['/keluarga', '/keluarga/residents', '/keluarga/residents/'.$e->id] as $url) {
            $response = $this->get($url)->assertOk();
            $page = $response->viewData('page');
            $json = json_encode($page);
            foreach (['PRIVATE-', 'source_reference', 'metadata', 'legacy_notes', 'responder', 'verifier', 'proof_path', 'paid_at',
                'invoice', 'payment', 'gait_observations', 'fall_incidents', '/monitoring', '/notifications', '/admin', $foreign->id] as $private) {
                $this->assertStringNotContainsString($private, $json);
            }
            $this->assertFalse($response->headers->has('X-Inertia-Devtools-Id'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame('staying', $this->detail($e)['resident']['service']['state']);
        $this->assertSame('123.45', $this->detail($e)['resident']['service']['enrollment']['monthly_price']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->get('/notifications')->assertForbidden();
    }
}
