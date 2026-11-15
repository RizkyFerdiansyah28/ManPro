<?php

namespace Tests\Feature\Database;

use App\Services\Billing\InvoiceHttpReadService;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceHttpTestCase;

#[Group('invoice-http')]
class InvoiceHttpFoundationTest extends InvoiceHttpTestCase
{
    public function test_read_only_readiness_and_guards_are_compatible_and_reconcile_never_starts_initial_issue(): void
    {
        $s = $this->source();
        $body = $this->httpCommand($s);
        $this->assertTrue(Auth::check(), 'Session hilang sesudah preview.');
        $before = $this->fingerprints();
        $reader = app(InvoiceHttpReadService::class);
        $reader->context($s['admin']->id, $s['elderly']->id);
        $this->actingAs($s['admin'])->getJson(self::HTTP_BASE.'/resident-options')->assertOk();
        $this->assertTrue(Auth::check(), 'Session hilang sesudah options.');
        $this->postJson(self::HTTP_BASE.'/reconcile', $this->reconcileBody($body))->assertStatus(409)->assertJsonPath('error.code', 'outcome_unresolved');
        $this->assertTrue(Auth::check(), 'Session hilang sesudah unresolved.');
        $this->preserve($before, []);
        $issued = $this->postJson(self::HTTP_BASE, $body)->assertCreated()->assertJsonPath('replayed', false);
        $id = $issued->json('invoice_id');
        $before = $this->fingerprints();
        $this->postJson(self::HTTP_BASE.'/reconcile', $this->reconcileBody($body))->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('invoice_id', $id);
        $detail = $reader->show($s['admin']->id, $id);
        $this->assertSame('3100.00', $detail['current_revision']['monthly_price']);
        $this->assertSame($s['family']->id, $detail['financial_owner']['id']);
        $this->assertSame('managed_v1', $detail['record_kind']);
        $index = $reader->index($s['admin']->id, ['elderly_id' => $s['elderly']->id]);
        $this->assertSame(1, $index['total']);
        $this->assertCount(1, $index['data']);
        $this->assertCount(1, $this->verifyInvoices([$s['elderly']->id]));
        $this->preserve($before, []);
    }
}
