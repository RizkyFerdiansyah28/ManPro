<?php

namespace Tests\Feature\Database;

use App\Services\Billing\LocalPaymentProofStore;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\Billing\StoredPaymentProof;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\PaymentHttpTestCase;

#[Group('payment-http')]
class PaymentHttpIntegrationTest extends PaymentHttpTestCase
{
    public function test_uppercase_uuid_routes_preserve_canonical_proof_binding(): void
    {
        $s = $this->httpSource();
        $s['invoice_url'] = '/keluarga/billing/invoices/'.strtoupper($s['issue']->invoiceId);
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->getJson('/billing/invoices/'.strtoupper($s['issue']->invoiceId).'/payments/'.strtoupper($payment).'/proof')->assertOk();
        $this->actingAs($s['admin']);
        $url = '/admin/billing/invoices/'.strtoupper($s['issue']->invoiceId).'/payments/'.strtoupper($payment);
        $token = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $this->postJson($url.'/verify', ['command_context' => $token, 'confirm' => true, 'bank_receipt_checked' => true])->assertOk()->assertJsonPath('payment_id', $payment);
    }

    public function test_error_envelope_all_required_statuses_and_native_throttle_do_not_gate_staff_alerts(): void
    {
        $s = $this->httpSource();
        $this->getJson('/keluarga/billing/invoices')->assertUnauthorized();
        $this->actingAs($s['family']);
        $this->get($s['invoice_url'], ['Accept' => 'text/html'])->assertStatus(406);
        $this->get($s['invoice_url'], ['Accept' => 'application/json;q=0'])->assertStatus(406);
        $this->getJson('/keluarga/billing/invoices/not-a-uuid')->assertNotFound();
        $this->getJson('/keluarga/billing/invoices?status=pending')->assertStatus(422);
        Log::spy();
        foreach ([401, 403, 404, 409, 413, 415, 419, 422, 429, 500, 503] as $status) {
            Route::get('/billing/payment-commands/test-error-'.$status, fn () => abort($status, 'Synthetic secret must never escape.'));
            $response = $this->getJson('/billing/payment-commands/test-error-'.$status)->assertStatus($status);
            $this->assertPrivate($response);
            $response->assertJsonStructure(['error' => ['code', 'message', 'retry_mode'], 'financial_outcome', 'correlation_id']);
            $response->assertDontSee('Synthetic secret');
        }
        for ($i = 0; $i < 30; $i++) {
            $this->getJson($s['invoice_url'].'/payment-context')->assertOk();
        }
        $this->assertPrivate($this->getJson($s['invoice_url'].'/payment-context')->assertStatus(429)->assertHeader('Retry-After'));
        $this->actingAs($s['admin']);
        $this->getJson('/admin/billing/payments?status=unpaid')->assertStatus(422);
        $this->getJson('/notifications/summary')->assertOk()->assertJsonStructure(['pending_count']);
    }

    public function test_family_submit_review_resubmit_verify_replay_and_reload_recovery(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $this->getJson('/keluarga/billing/invoices')->assertOk()->assertJsonFragment(['id' => $s['issue']->invoiceId, 'elderly_id' => $s['elderly']->id]);
        $detail = $this->getJson($s['invoice_url'])->assertOk()->assertJsonPath('invoice.elderly_id', $s['elderly']->id);
        $this->assertPrivate($detail);
        $token = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
        $before = $this->fingerprints();
        $response = $this->submitHttp($s, $token)->assertCreated()->assertJsonPath('command_fact', 'submission_recorded')->assertJsonPath('amount', '3100.00');
        $this->assertPrivate($response);
        $payment = $response->json('payment_id');
        $this->unchanged($before, ['payments', 'billing_requests', 'billing_actions']);
        $this->submitHttp($s, $token)->assertOk()->assertJsonPath('payment_id', $payment)->assertJsonPath('replayed', true);
        $committed = $this->fingerprints();
        $this->reconcileHttp($token)->assertOk()->assertJsonPath('payment_id', $payment);
        $this->unchanged($committed);
        $proof = $this->getJson('/billing/invoices/'.$s['issue']->invoiceId.'/payments/'.$payment.'/proof')->assertOk();
        $this->assertSame(hash('sha256', $this->image()), hash('sha256', $proof->getContent()));
        $this->assertStringStartsWith('attachment;', $proof->headers->get('Content-Disposition'));
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $payment);
        $found = false;
        for ($page = 1; $page <= 100; $page++) {
            $queue = $this->getJson('/admin/billing/payments?page='.$page)->assertOk();
            $this->assertPrivate($queue);
            $found = $found || in_array($payment, array_column($queue->json('data'), 'id'), true);
            if ($found || ! $queue->json('has_more')) {
                break;
            }
        }
        $this->assertTrue($found, 'Payment fixture harus ditemukan pada antrean paginated, bukan diasumsikan ada di halaman pertama.');
        $this->assertPrivate($this->getJson($url)->assertOk());
        $reject = $this->getJson($url.'/review-context?operation=reject')->assertOk()->json('command_context');
        $body = ['command_context' => $reject, 'confirm' => true, 'reason_code' => 'bank_not_found', 'public_reason' => 'Transfer belum ditemukan.', 'private_note' => 'Synthetic private note.'];
        $this->postJson($url.'/reject', $body)->assertOk()->assertJsonPath('command_fact', 'rejection_recorded');
        $this->reconcileHttp($reject)->assertOk()->assertJsonPath('command_fact', 'rejection_recorded');
        $this->postJson($url.'/reject', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->actingAs($s['family']);
        $this->reconcileHttp($token)->assertOk()->assertJsonPath('command_fact', 'submission_recorded');
        $next = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->assertNotSame($payment, $next);
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $next);
        $verify = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $verified = $this->postJson($url.'/verify', ['command_context' => $verify, 'confirm' => true, 'bank_receipt_checked' => true])->assertOk();
        $this->assertPrivate($verified);
        $this->reconcileHttp($verify)->assertOk()->assertJsonPath('command_fact', 'verification_recorded');
        $this->assertSame('paid', DB::table('invoices')->where('id', $s['issue']->invoiceId)->value('status'));
        $this->assertSame(2, DB::table('payments')->where('invoice_id', $s['issue']->invoiceId)->count());
        $this->actingAs($s['family']);
        $this->reconcileHttp($token)->assertOk()->assertJsonPath('command_fact', 'submission_recorded');
        $this->getJson($s['invoice_url'].'/payment-context')->assertStatus(409);
    }

    public function test_foreign_old_new_owner_roles_and_nested_parent_are_scoped(): void
    {
        $s = $this->httpSource();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $proof = '/billing/invoices/'.$s['issue']->invoiceId.'/payments/'.$payment.'/proof';
        $this->actingAs($s['next']);
        foreach ([$s['invoice_url'], $s['invoice_url'].'/payment-context', $proof] as $path) {
            $this->assertPrivate($this->getJson($path)->assertNotFound());
        }
        $this->getJson('/keluarga/billing/invoices')->assertOk()->assertJsonMissing(['id' => $s['issue']->invoiceId]);
        $this->actingAs($this->account('perawat'));
        $this->getJson($s['invoice_url'])->assertForbidden();
        $this->getJson($proof)->assertForbidden();
        $this->getJson('/admin/billing/payments')->assertForbidden();
        $this->sourceTransfer($s, '2026-11-10 10:00:00+07');
        foreach ([$s['family'], $s['next']] as $family) {
            $this->actingAs($family);
            $this->getJson($s['invoice_url'])->assertNotFound();
            $this->getJson($proof)->assertNotFound();
        }
        $this->actingAs($s['admin']);
        $this->getJson('/admin/billing/invoices/'.Str::uuid().'/payments/'.$payment)->assertNotFound();
        $this->getJson($this->reviewUrl($s, $payment))->assertOk();
        $this->getJson($proof)->assertOk();
        $this->actingAs($this->account('admin', false));
        $this->getJson('/admin/billing/payments')->assertUnauthorized();
        Auth::forgetGuards();
        $this->getJson('/admin/billing/payments')->assertUnauthorized();
    }

    public static function invalidSubmissions(): array
    {
        return array_map(fn ($case) => [$case], ['amount', 'owner', 'status', 'proof_path', 'revision', 'tampered', 'expired', 'wrong_actor', 'csrf_missing', 'csrf_invalid', 'json', 'inertia', 'query', 'empty', 'extension', 'corrupt', 'oversize', 'body_limit']);
    }

    #[DataProvider('invalidSubmissions')]
    public function test_rejected_request_has_no_financial_effect_and_private_error(string $case): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $token = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
        $before = $this->fingerprints();
        $extra = [];
        $file = null;
        $expected = 422;
        switch ($case) {
            case 'amount': $extra['amount'] = '0.01';
                break;
            case 'owner': $extra['actor_id'] = $s['next']->id;
                break;
            case 'status': $extra['status'] = 'verified';
                break;
            case 'proof_path': $extra['proof_path'] = '../../secret';
                break;
            case 'revision':
            case 'expired':
                $data = json_decode(Crypt::decryptString($token), true);
                if ($case === 'revision') {
                    $data['revision_id'] = (string) Str::uuid();
                    $expected = 409;
                } else {
                    $data['issued_at'] -= 600;
                    $data['expires_at'] -= 600;
                    $expected = 409;
                }
                $token = Crypt::encryptString(json_encode($data));
                break;
            case 'tampered': $token = 'invalid';
                break;
            case 'wrong_actor': $this->actingAs($s['next']);
                $expected = 404;
                break;
            case 'csrf_missing': $this->withoutHeader('X-CSRF-TOKEN');
                $expected = 419;
                break;
            case 'csrf_invalid': $this->withHeader('X-CSRF-TOKEN', 'invalid');
                $expected = 419;
                break;
            case 'empty': $file = $this->upload('');
                break;
            case 'extension': $file = $this->upload(null, 'receipt.exe');
                break;
            case 'corrupt': $file = $this->upload('corrupt');
                break;
            case 'oversize': $file = $this->upload(str_repeat('x', 2097153));
                $expected = 413;
                break;
            case 'inertia': $this->withHeader('X-Inertia', 'true');
                $expected = 406;
                break;
            case 'query': $expected = 422;
                break;
            case 'json': $expected = 415;
                break;
            case 'body_limit': $this->withServerVariables(['CONTENT_LENGTH' => '2162689']);
                $expected = 413;
                break;
        }
        if ($case === 'json') {
            $response = $this->postJson($s['invoice_url'].'/payments', ['command_context' => $token, 'confirm' => true]);
        } else {
            $response = $this->post($s['invoice_url'].'/payments'.($case === 'query' ? '?command_context=secret' : ''),
                ['command_context' => $token, 'confirm' => '1', 'proof' => $file ?? $this->upload(), ...$extra], ['Content-Type' => 'multipart/form-data; boundary=synthetic-p94c']);
        }
        $response->assertStatus($expected);
        $this->assertPrivate($response);
        $this->unchanged($before);
    }

    public function test_expired_recovery_absent_session_rotation_and_tampered_recovery(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $token = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
        $before = $this->fingerprints();
        $this->reconcileHttp($token)->assertStatus(409)->assertJsonPath('error.code', 'outcome_unresolved');
        $this->unchanged($before);
        $payment = $this->submitHttp($s, $token)->assertCreated()->json('payment_id');
        $data = json_decode(Crypt::decryptString($token), true);
        $data['issued_at'] -= 600;
        $data['expires_at'] -= 600;
        $expired = Crypt::encryptString(json_encode($data));
        $this->reconcileHttp($expired)->assertOk()->assertJsonPath('payment_id', $payment);
        $this->postJson('/billing/payment-commands/reconcile', ['command_context' => $token, 'recovery_context' => 'invalid'])->assertStatus(422);
        $this->app['session']->driver()->migrate(true);
        $this->withCookie(config('session.cookie'), app('session')->driver()->getId());
        $this->reconcileHttp($token)->assertStatus(422)->assertJsonPath('error.code', 'context_invalid');
    }

    public function test_storage_crash_orphan_binding_integrity_and_no_overwrite(): void
    {
        $s = $this->httpSource();
        $store = app(LocalPaymentProofStore::class);
        $ref = (string) Str::uuid();
        $file = $this->upload();
        $proof = $store->intake($file, $ref, $s['family']->id, $s['issue']->invoiceId);
        $this->assertSame($proof->row(), $store->intake($file, $ref, $s['family']->id, $s['issue']->invoiceId)->row());
        $this->paymentFailure('invalid_proof', fn () => $store->resolve($ref, $s['next']->id, $s['issue']->invoiceId));
        $this->paymentFailure('invalid_proof', fn () => $store->resolve($ref, $s['family']->id, (string) Str::uuid()));
        $this->paymentFailure('invalid_proof', fn () => $store->resolve('../escape', $s['family']->id, $s['issue']->invoiceId));
        $this->paymentFailure('idempotency_conflict', fn () => $store->intake($this->upload($this->image('png', 3)), $ref, $s['family']->id, $s['issue']->invoiceId));
        $this->assertSame(0, DB::table('payments')->where('invoice_id', $s['issue']->invoiceId)->count());
        $root = app(PaymentProofEnvironment::class)->root();
        $object = $root.'/'.$ref.'/object.png';
        $bad = new StoredPaymentProof($proof->path, str_repeat('0', 64), $proof->size, $proof->mime);
        $this->paymentFailure('proof_integrity_failure', fn () => $store->assertStored($bad, $s['family']->id, $s['issue']->invoiceId));
        $this->assertSame(7, file_put_contents($object, 'corrupt'));
        $this->paymentFailure('proof_integrity_failure', fn () => $store->assertStored($proof, $s['family']->id, $s['issue']->invoiceId));
        $this->assertTrue(unlink($object));
        $this->paymentFailure('proof_integrity_failure', fn () => $store->bytes($proof, $s['family']->id, $s['issue']->invoiceId));
        $partial = (string) Str::uuid();
        $this->assertTrue(mkdir($root.'/'.$partial));
        file_put_contents($root.'/'.$partial.'/object.png', $this->image());
        $this->paymentFailure('proof_storage_unavailable', fn () => $store->intake($file, $partial, $s['family']->id, $s['issue']->invoiceId));
        $inventory = array_column($store->inventory(), null, 'reference');
        $this->assertFalse($inventory[$partial]['registry_present']);
        $this->assertTrue($inventory[$partial]['object_present']);
        $this->assertSame('manual_review_required', $inventory[$partial]['disposition']);
    }

    public function test_symlink_or_windows_junction_escape_is_rejected_without_modifying_target(): void
    {
        $s = $this->httpSource();
        $root = app(PaymentProofEnvironment::class)->root();
        $this->upload(); // Create the disposable owned root only.
        $target = $root.'/synthetic-target';
        $this->assertTrue(mkdir($target));
        $this->assertSame(9, file_put_contents($target.'/sentinel', 'unchanged'));
        $link = $root.'/'.Str::uuid();
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['cmd.exe', '/d', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $target)]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'Synthetic junction creation must succeed; otherwise isolation remains unverified.');
        } else {
            $this->assertTrue(symlink($target, $link));
        }
        try {
            $this->assertTrue(is_link($link) || strcasecmp(str_replace('\\', '/', realpath($link)), str_replace('\\', '/', $link)) !== 0);
            $this->paymentFailure('proof_storage_unavailable', fn () => app(LocalPaymentProofStore::class)->intake($this->upload(), basename($link), $s['family']->id, $s['issue']->invoiceId));
            $this->assertSame('unchanged', file_get_contents($target.'/sentinel'));
        } finally {
            // Remove only the exact synthetic link, never recurse through it.
            PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
        }
    }

    public function test_storage_write_failure_and_operational_admission_are_fail_closed(): void
    {
        $s = $this->httpSource();
        $file = $this->upload();
        $root = app(PaymentProofEnvironment::class)->root();
        $ref = (string) Str::uuid();
        file_put_contents($root.'/'.$ref, 'blocked-directory');
        $before = $this->fingerprints();
        $this->paymentFailure('proof_storage_unavailable', fn () => app(LocalPaymentProofStore::class)->intake($file, $ref, $s['family']->id, $s['issue']->invoiceId));
        $this->unchanged($before);
        config(['payment_http.synthetic_validation' => false]);
        try {
            $this->paymentFailure('proof_boundary_unavailable', fn () => app(PaymentProofEnvironment::class)->root());
        } finally {
            config(['payment_http.synthetic_validation' => true]);
        }
        $this->actingAs($s['family']);
        config(['payment_http.enabled' => false]);
        try {
            $this->assertPrivate($this->getJson($s['invoice_url'])->assertStatus(503));
        } finally {
            config(['payment_http.enabled' => true]);
        }
    }

    public function test_sql_failure_retains_private_orphan_and_reconcile_does_not_submit(): void
    {
        $s = $this->httpSource();
        $this->actingAs($s['family']);
        $token = $this->getJson($s['invoice_url'].'/payment-context')->assertOk()->json('command_context');
        $this->paymentFault('deferred');
        $before = $this->fingerprints();
        $this->submitHttp($s, $token)->assertStatus(500)->assertJsonPath('error.code', 'integrity_violation');
        $this->unchanged($before);
        $this->reconcileHttp($token)->assertStatus(409)->assertJsonPath('error.code', 'outcome_unresolved');
        $this->unchanged($before);
        $facts = json_decode(Crypt::decryptString($token), true);
        $proof = app(LocalPaymentProofStore::class)->resolve($facts['proof_reference'], $s['family']->id, $s['issue']->invoiceId);
        app(LocalPaymentProofStore::class)->assertStored($proof, $s['family']->id, $s['issue']->invoiceId);
    }

    public function test_review_confirm_bank_state_and_operation_are_not_client_manipulable(): void
    {
        $s = $this->httpSource();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $payment);
        $token = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $before = $this->fingerprints();
        foreach ([['confirm' => false, 'bank_receipt_checked' => true], ['confirm' => true, 'bank_receipt_checked' => false], ['confirm' => true, 'bank_receipt_checked' => true, 'amount' => '1.00']] as $body) {
            $this->assertPrivate($this->postJson($url.'/verify', ['command_context' => $token, ...$body])->assertStatus(422));
            $this->unchanged($before);
        }
        $this->postJson($url.'/reject', ['command_context' => $token, 'confirm' => true, 'reason_code' => 'missing', 'public_reason' => 'Synthetic rejection.'])->assertStatus(422);
        $this->unchanged($before);
        $this->postJson($url.'/verify', ['command_context' => $token, 'confirm' => true, 'bank_receipt_checked' => true])->assertOk();
    }

    public function test_cross_operation_request_collision_cannot_replace_original_durable_outcome(): void
    {
        $s = $this->httpSource();
        $payment = $this->submitHttp($s)->assertCreated()->json('payment_id');
        $this->actingAs($s['admin']);
        $url = $this->reviewUrl($s, $payment);
        $verify = $this->getJson($url.'/review-context?operation=verify')->assertOk()->json('command_context');
        $reject = $this->getJson($url.'/review-context?operation=reject')->assertOk()->json('command_context');
        // Synthetic server-signed collision; a client cannot modify the authenticated envelope.
        $one = json_decode(Crypt::decryptString($verify), true);
        $two = json_decode(Crypt::decryptString($reject), true);
        $two['request_id'] = $one['request_id'];
        $reject = Crypt::encryptString(json_encode($two));
        $this->postJson($url.'/verify', ['command_context' => $verify, 'confirm' => true, 'bank_receipt_checked' => true])->assertOk();
        $before = $this->fingerprints();
        $response = $this->postJson($url.'/reject', ['command_context' => $reject, 'confirm' => true, 'reason_code' => 'not_found', 'public_reason' => 'Synthetic reason.']);
        $this->assertPrivate($response->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict'));
        $this->unchanged($before);
        $this->reconcileHttp($verify)->assertOk()->assertJsonPath('command_fact', 'verification_recorded');
        $this->unchanged($before);
    }
}
