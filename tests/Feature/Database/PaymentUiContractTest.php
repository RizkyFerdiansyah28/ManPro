<?php

namespace Tests\Feature\Database;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PaymentHttpTestCase;

#[Group('payment-ui')]
class PaymentUiContractTest extends PaymentHttpTestCase
{
    public function test_page_shells_and_minimum_read_labels_preserve_financial_data(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $before = $this->fingerprints();
        $this->get('/keluarga/tagihan')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Family/Payments/Index')->has('payment_ui_context')->missing('invoices'));
        $detail = '/keluarga/tagihan/'.$s['issue']->invoiceId;
        $this->get($detail)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Family/Payments/Show')->where('invoice_id', $s['issue']->invoiceId)->missing('payments'));
        $this->getJson($s['invoice_url'])->assertOk()->assertJsonPath('invoice.resident.name_current', $s['elderly']->name)->assertJsonMissingPath('invoice.financial_owner');
        $this->unchanged($before);
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->actingAs($s['admin']);
        $before = $this->fingerprints();
        $this->get('/admin/pembayaran')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Payments/Index'));
        $this->get('/admin/pembayaran/'.$s['issue']->invoiceId.'/'.$payment)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Payments/Show')->where('payment_id', $payment));
        $response = $this->getJson($this->reviewUrl($s, $payment))->assertOk()
            ->assertJsonPath('invoice.resident.id', $s['elderly']->id)->assertJsonPath('invoice.financial_owner.id', $s['family']->id)
            ->assertJsonPath('invoice.amount', '3100.00')->assertJsonPath('payment.amount', '3100.00');
        $this->assertPrivate($response);
        $this->unchanged($before);
    }

    public function test_ui_context_is_not_a_command_and_hold_is_stable_across_same_actor_session_rotation(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $url = '/keluarga/tagihan/'.$s['issue']->invoiceId;
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? ''];
        $first = $this->getJson($url, $headers)->assertOk()->json('props.payment_ui_context');
        foreach (['session_context', 'audience_context', 'hold_key'] as $key) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first[$key]);
        }
        foreach (['command_context', 'request_id', 'proof_reference', 'expected_state', 'private_note'] as $key) {
            $this->assertArrayNotHasKey($key, $first);
        }
        $this->app['session']->driver()->migrate(true);
        $this->withCookie(config('session.cookie'), app('session')->driver()->getId());
        $this->actingAs($s['family']);
        $next = $this->getJson($url, $headers)->assertOk()->json('props.payment_ui_context');
        $this->assertNotSame($first['session_context'], $next['session_context']);
        $this->assertSame($first['hold_key'], $next['hold_key']);
        $this->assertSame($first['audience_context'], $next['audience_context']);
        $this->actingAs($s['admin']);
        $other = $this->getJson('/admin/pembayaran', $headers)->assertOk()->json('props.payment_ui_context');
        $this->assertNotSame($next['hold_key'], $other['hold_key']);
        $this->assertSame(0, DB::table('payments')->where('invoice_id', $s['issue']->invoiceId)->count());
    }

    public function test_family_scope_transfer_roles_parent_mismatch_and_private_errors(): void
    {
        $s = $this->httpSource();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $family = '/keluarga/tagihan/'.$s['issue']->invoiceId;
        $admin = '/admin/pembayaran/'.$s['issue']->invoiceId.'/'.$payment;
        $this->actingAs($s['next']);
        $this->get($family)->assertNotFound();
        $this->actingAs($this->account('perawat'));
        $this->get('/keluarga/tagihan')->assertForbidden();
        $this->get('/admin/pembayaran')->assertForbidden();
        $this->sourceTransfer($s, '2026-11-10 10:00:00+07');
        foreach ([$s['family'], $s['next']] as $actor) {
            $this->actingAs($actor);
            $this->get($family)->assertNotFound();
        }
        $this->actingAs($s['admin']);
        $this->get($admin)->assertOk();
        $this->get('/admin/pembayaran/'.Str::uuid().'/'.$payment)->assertNotFound();
        $response = $this->get('/admin/pembayaran?command_context=SYNTHETIC-SECRET')->assertStatus(422);
        $response->assertDontSee('SYNTHETIC-SECRET');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs($this->account('admin', false));
        $this->get('/admin/pembayaran')->assertRedirect('/login');
    }

    public function test_ui_shell_does_not_enable_operational_intake_or_turn_api_into_inertia(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $this->get($s['invoice_url'], ['Accept' => 'text/html'])->assertStatus(406);
        config(['payment_http.enabled' => false]);
        try {
            $before = $this->fingerprints();
            $this->get('/keluarga/tagihan')->assertStatus(503);
            $this->unchanged($before);
        } finally {
            config(['payment_http.enabled' => true]);
        }
    }
}
