<?php

namespace Tests\Feature\Database;

use App\Services\Billing\LocalPaymentProofStore;
use App\Services\Billing\PaymentProofEnvironment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PaymentHttpTestCase;

/** P9-4E: adversarial acceptance only; all fixtures use the guarded owned runner. */
#[Group('payment-http')]
class PaymentSecurityAcceptanceTest extends PaymentHttpTestCase
{
    public static function bindings(): array
    {
        return array_map(fn ($field) => [$field], ['actor', 'session', 'audience', 'purpose', 'operation', 'invoice_id', 'payment_id', 'v', 'issued_at', 'expires_at', 'request_id']);
    }

    #[DataProvider('bindings')]
    public function test_signed_but_wrong_context_binding_never_publishes_or_mutates(string $field): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $token = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
        $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        $data[$field] = match ($field) {
            'actor', 'invoice_id', 'payment_id' => (string) Str::uuid(),
            'v' => 2, 'issued_at' => time() + 600,
            'expires_at' => $data['expires_at'] + 1,
            'operation' => 'verify', default => 'synthetic_wrong_binding',
        };
        $wrong = Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
        $file = $this->upload();
        $before = $this->fingerprints();
        $response = $this->submitHttp($s, $wrong, $file)->assertStatus(422)->assertJsonPath('error.code', 'context_invalid');
        $this->assertPrivate($response);
        $this->unchanged($before);
        $this->assertSame([], app(LocalPaymentProofStore::class)->inventory());
    }

    public function test_current_owner_label_does_not_rewrite_frozen_invoice_owner_and_reads_are_nonmutating(): void
    {
        $s = $this->httpSource();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $s['family']->update(['name' => 'Direktori keluarga sintetis terkini']);
        $this->actingAs($s['admin']);
        $before = $this->fingerprints();
        $url = $this->reviewUrl($s, $payment);
        $detail = $this->getJson($url)->assertOk()
            ->assertJsonPath('invoice.financial_owner.id', $s['family']->id)
            ->assertJsonPath('invoice.financial_owner.name_current', 'Direktori keluarga sintetis terkini');
        $this->assertPrivate($detail);
        $this->get('/admin/pembayaran/'.$s['issue']->invoiceId.'/'.$payment)->assertOk();
        $verify = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $this->getJson($url.'/review-context?operation=reject')->assertOk();
        $this->getJson('/billing/payment-commands/recovery-context')->assertOk();
        $this->getJson('/billing/invoices/'.$s['issue']->invoiceId.'/payments/'.$payment.'/proof')->assertOk();
        $this->unchanged($before);
        $response = $this->postJson($url.'/reject', ['command_context' => $verify, 'confirm' => true,
            'reason_code' => 'not_received', 'public_reason' => 'Alasan sintetis.'])->assertStatus(422)->assertJsonPath('error.code', 'context_invalid');
        $this->assertPrivate($response);
        $this->unchanged($before);
    }

    public static function proofFaults(): array
    {
        return [['corrupt_object'], ['missing_object'], ['corrupt_registry'], ['wrong_registry_owner']];
    }

    #[DataProvider('proofFaults')]
    public function test_real_private_proof_fault_blocks_download_and_verification_without_financial_repair(string $fault): void
    {
        $s = $this->httpSource();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $payment);
        $token = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $row = DB::table('payments')->where('id', $payment)->first();
        $environment = app(PaymentProofEnvironment::class);
        $root = $environment->root();
        $reference = explode('/', $row->proof_path)[1];
        $this->assertTrue(Str::isUuid($reference));
        $directory = $root.'/'.$reference;
        $path = $directory.'/'.(str_contains($fault, 'registry') ? 'registry.json' : basename($row->proof_path));
        $environment->confined($path);
        $this->assertStringStartsWith(realpath($root).DIRECTORY_SEPARATOR, realpath($path));
        $this->assertFalse(is_link($path));
        // Fault injection touches only the exact disposable synthetic object just created above.
        $original = file_get_contents($path);
        $before = $this->fingerprints();
        try {
            if ($fault === 'missing_object') {
                $this->assertTrue(unlink($path));
            } elseif ($fault === 'wrong_registry_owner') {
                $registry = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
                $registry['submitter'] = $s['next']->id;
                $this->assertNotFalse(file_put_contents($path, json_encode($registry, JSON_THROW_ON_ERROR)));
            } else {
                $this->assertNotFalse(file_put_contents($path, 'benign synthetic corruption'));
            }
            $code = match ($fault) {
                'wrong_registry_owner' => 'invalid_proof', 'corrupt_registry' => 'proof_storage_unavailable', default => 'proof_integrity_failure',
            };
            $status = $fault === 'wrong_registry_owner' ? 422 : 503;
            $download = $this->getJson('/billing/invoices/'.$s['issue']->invoiceId.'/payments/'.$payment.'/proof')->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->assertPrivate($download);
            $verify = $this->postJson($url.'/verify', ['command_context' => $token, 'confirm' => true, 'bank_receipt_checked' => true])->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->assertPrivate($verify);
            $this->unchanged($before);
            $this->assertSame('pending', DB::table('payments')->where('id', $payment)->value('status'));
            $this->assertSame('unpaid', DB::table('invoices')->where('id', $s['issue']->invoiceId)->value('status'));
        } finally {
            // Restore this test fixture only so inherited disposable-root cleanup can run.
            $environment->confined($path);
            $this->assertSame(strlen($original), file_put_contents($path, $original));
        }
    }

    public function test_staff_safety_access_does_not_depend_on_unpaid_pending_rejected_paid_or_disabled_payment_gates(): void
    {
        $s = $this->httpSource();
        $nurse = $this->account('perawat');
        $check = function () use ($nurse): void {
            $this->actingAs($nurse);
            foreach (['/perawat', '/monitoring/records', '/monitoring/open-incidents', '/notifications/summary'] as $path) {
                $this->get($path)->assertOk();
            }
        };
        $check();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $check();
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $payment);
        $token = $this->getJson($url.'/review-context?operation=reject')->assertOk()->json('command_context');
        $this->postJson($url.'/reject', ['command_context' => $token, 'confirm' => true, 'reason_code' => 'not_received', 'public_reason' => 'Alasan sintetis.'])->assertOk();
        $check();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $payment);
        $token = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $this->postJson($url.'/verify', ['command_context' => $token, 'confirm' => true, 'bank_receipt_checked' => true])->assertOk();
        $check();
        config(['payment_core.enabled' => false, 'payment_http.enabled' => false]);
        try {
            $check();
        } finally {
            config(['payment_core.enabled' => true, 'payment_http.enabled' => true]);
        }
    }
}
