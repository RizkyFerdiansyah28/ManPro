<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\User;
use App\Services\Billing\AcademicProofScanner;
use App\Services\ResidentService;
use Database\Seeders\ServiceClassSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Tests\Support\PaymentR2IntegrationObserver as Observer;

final class BillingC3Fixtures
{
    public const TARGETS = BillingC3Admission::TARGETS;

    private const BILLING_TABLES = ['invoices', 'invoice_revisions', 'payments', 'proof_scan_subjects', 'proof_scan_jobs',
        'proof_scan_attempts', 'proof_scan_verdicts', 'billing_requests'];

    public static function prepare(): array
    {
        self::assertEmptyBilling();
        Identity::require(! DB::table('proof_scan_policies')->exists() && ! DB::table('proof_scan_policy_heads')->exists()
            && ! DB::table('proof_scan_audit_events')->exists(), 'billing_c3_fixture_policy_not_empty');
        $password = 'C3!'.bin2hex(random_bytes(24));
        $accounts = [];
        foreach (['a' => ['keluarga', true], 'b' => ['keluarga', true], 'admin' => ['admin', true],
            'inactive' => ['admin', false], 'nurse' => ['perawat', true]] as $tag => [$role, $active]) {
            $accounts[$tag] = User::factory()->create(['name' => 'Simulasi C3 '.$tag, 'role' => $role, 'is_active' => $active,
                'email' => 'c3-'.Str::uuid().'@example.test', 'password' => $password]);
        }
        (new ServiceClassSeeder)->run();
        $start = (new DateTimeImmutable('first day of last month', new DateTimeZone('Asia/Jakarta')))->setTime(0, 0);
        $sources = [];
        $support = new PaymentR2IntegrationFixtures;
        foreach (self::TARGETS as $tag) {
            $family = $accounts[$tag === 'foreign' ? 'b' : 'a'];
            $amount = $tag === 'zero' ? '0.00' : '3100.00';
            [$resident, $enrollment] = $support->sourceRecords($accounts['admin'], $family, 'C3 '.$tag, $amount, $start);
            $sources[$tag] = ['resident' => $resident->id, 'label' => $resident->name, 'enrollment' => $enrollment->id,
                'family' => $family->id, 'admin' => $accounts['admin']->id, 'month' => $start->format('Y-m-d'), 'amount' => $amount];
        }
        // Admission requires the immutable policy to be committed before the head is activated.
        $policy = DB::transaction(static fn () => DB::scalar('SELECT public.proof_scan_create_policy(?::jsonb, public.proof_scan_authority())',
            [json_encode(PaymentR2IntegrationFixtures::policyProfile(), JSON_THROW_ON_ERROR)]), 1);
        $epoch = DB::transaction(static fn () => DB::scalar('SELECT public.proof_scan_set_head(?,?,?,?,public.proof_scan_authority())',
            [$policy, (string) Str::uuid(), 0, 'active']), 1);
        Identity::require($epoch === 1, 'billing_c3_fixture_policy_not_active');
        self::assertEmptyBilling();
        Identity::require(! DB::table('proof_scan_audit_events')->whereNotNull('subject_id')->exists(), 'billing_c3_fixture_subject_audit_not_empty');
        $users = array_map(fn (User $user) => $user->only('id', 'email'), $accounts);
        Identity::write(Runtime::root().'/c3-sources.json', ['sources' => $sources, 'next_family' => $accounts['b']->id]);
        // C2 captures subprocess stdout on disk. Keep browser-only credentials in a distinct private file.
        Identity::write(Runtime::root().'/c3-browser-fixture.json', ['ok' => true, 'sources' => $sources, 'accounts' => $users,
            'password' => $password, 'proof' => base64_encode(AcademicProofScanner::fixtureBytes('clean')),
            'unknown_proof' => base64_encode(AcademicProofScanner::fixtureBytes('unknown'))]);

        return ['initial_empty' => true];
    }

    private static function assertEmptyBilling(): void
    {
        foreach (self::BILLING_TABLES as $table) {
            Identity::require(! DB::table($table)->exists(), 'billing_c3_fixture_financial_state_not_empty');
        }
    }

    public static function before(string $tag, PostgresTestGuard $guard): array
    {
        $source = self::source($tag);

        return Observer::read(static function (Connection $db) use ($source): array {
            $rows = $db->table('invoices')->where('elderly_id', $source['resident'])->where('billing_month', $source['month'])->count();
            Identity::require($rows === 0 && ! $db->table('invoice_revisions')->where('elderly_id', $source['resident'])->exists(),
                'billing_c3_invoice_already_exists');

            return ['invoices' => 0, 'revisions' => 0];
        }, $guard);
    }

    public static function bind(string $tag, string $invoice, PostgresTestGuard $guard): array
    {
        $source = self::source($tag);
        $issued = Observer::read(static function (Connection $db) use ($source, $invoice): array {
            $root = $db->table('invoices')->where('elderly_id', $source['resident'])->where('billing_month', $source['month'])->sole();
            $revision = $db->table('invoice_revisions')->where('invoice_id', $root->id)->sole();
            $request = $db->table('billing_requests')->where('invoice_id', $root->id)->sole();
            $action = $db->table('billing_actions')->where(['invoice_id' => $root->id, 'action' => 'invoice_issued'])->sole();
            [$due, $grace] = BillingC3Acceptance::expectedDates($root->initial_issued_at);
            Identity::require($root->id === $invoice && $root->billing_schema_version === 1 && $root->initial_issued_by === $source['admin']
                && $root->bill_to_family_id === $source['family'] && $root->service_enrollment_id === $source['enrollment']
                && $root->financial_ownership_period_id !== null && $root->amount === $source['amount']
                && $root->status === ($source['amount'] === '0.00' ? 'paid' : 'unpaid') && $root->due_date === $due && $root->grace_period_ends_on === $grace
                && $revision->id === $root->current_revision_id && $revision->revision_no === 1 && $revision->monthly_price === $source['amount']
                && $revision->amount === $root->amount && $request->operation === 'issue' && $request->actor_id === $source['admin']
                && $request->outcome_revision_id === $revision->id && $action->actor_id === $source['admin']
                && $action->revision_id === $revision->id && $action->billing_request_id === $request->id
                && ! $db->table('payments')->where('invoice_id', $invoice)->exists()
                && ! $db->table('proof_scan_subjects')->where('invoice_id', $invoice)->exists(), 'billing_c3_issued_graph_mismatch');

            return ['revision' => $revision->id, 'amount' => $root->amount, 'status' => $root->status, 'due' => $due, 'grace' => $grace];
        }, $guard);
        Identity::write(Runtime::root().'/c3-bound-'.$tag.'.json', ['tag' => $tag, 'invoice' => $invoice]);

        return $issued;
    }

    public static function sources(): array
    {
        return Identity::read(Runtime::root().'/c3-sources.json')['sources'];
    }

    private static function source(string $tag): array
    {
        Identity::require(in_array($tag, self::TARGETS, true), 'billing_c3_fixture_target_invalid');

        return self::sources()[$tag];
    }

    public static function target(string $invoice): string
    {
        foreach (self::TARGETS as $tag) {
            $path = Runtime::path(Runtime::root().'/c3-bound-'.$tag.'.json');
            if (is_file($path) && Identity::read($path) === ['tag' => $tag, 'invoice' => $invoice]) {
                return $tag;
            }
        }

        throw new \LogicException('billing_c3_fixture_target_unbound');
    }

    public static function scan(string $invoice, string $payment): array
    {
        $tag = self::target($invoice);
        Identity::require(in_array($tag, ['primary', 'transfer', 'rejection', 'unknown', 'stale', 'ttl', 'verify-loss'], true),
            'billing_c3_scanner_target_denied');
        $job = DB::table('proof_scan_jobs as j')->join('proof_scan_subjects as q', 'q.id', '=', 'j.subject_id')
            ->whereIn('j.state', ['READY', 'RETRY_WAIT'])->whereColumn('j.generation', 'q.generation')
            ->sole(['j.id', 'j.subject_id', 'j.generation', 'j.fence', 'q.invoice_id', 'q.payment_id', 'q.state as subject_state', 'j.state']);
        Identity::require($job->invoice_id === $invoice && $job->payment_id === $payment && $job->subject_state === 'QUEUED'
            && $job->state === 'READY' && ! DB::table('proof_scan_attempts')->whereIn('state', ['LEASED', 'RUNNING'])->exists(),
            'billing_c3_scanner_claim_candidate_ambiguous');
        putenv('SILANSIA_ACADEMIC_PROOF_SCAN=1');
        try {
            $result = PaymentR2IntegrationFixtures::scanTarget($invoice, $payment,
                $tag === 'unknown' ? ['unknown', 'UNKNOWN_HELD'] : ['clean', 'CLEAN_ELIGIBLE']);
        } finally {
            putenv('SILANSIA_ACADEMIC_PROOF_SCAN');
        }
        $accepted = DB::table('proof_scan_jobs as j')->join('proof_scan_attempts as a', 'a.id', '=', 'j.current_attempt_id')
            ->join('proof_scan_verdicts as v', 'v.attempt_id', '=', 'a.id')
            ->where(['j.id' => $job->id, 'j.subject_id' => $job->subject_id, 'j.generation' => $job->generation,
                'j.state' => $tag === 'unknown' ? 'RETRY_WAIT' : 'COMPLETED', 'a.job_id' => $job->id, 'a.state' => 'ACCEPTED'])
            ->whereColumn('a.fence', 'j.fence')->whereColumn('v.fence', 'j.fence')->whereColumn('v.generation', 'j.generation')
            ->sole(['j.id', 'j.generation', 'j.fence']);
        Identity::require((int) $accepted->fence === (int) $job->fence + 1, 'billing_c3_scanner_fence_mismatch');

        return $result + ['job' => $accepted->id, 'generation' => $accepted->generation, 'fence' => $accepted->fence];
    }

    public static function expireContext(string $invoice): string
    {
        Identity::require(self::target($invoice) === 'rejection', 'billing_c3_expiry_scope_invalid');
        $token = stream_get_contents(STDIN, 12001);
        Identity::require(is_string($token) && strlen($token) <= 12000, 'billing_c3_expiry_context_invalid');
        $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        Identity::require(($data['purpose'] ?? null) === 'command' && ($data['operation'] ?? null) === 'submit'
            && ($data['invoice_id'] ?? null) === $invoice && ($data['actor'] ?? null) === self::source('rejection')['family']
            && is_int($data['issued_at'] ?? null) && is_int($data['expires_at'] ?? null) && $data['expires_at'] - $data['issued_at'] === 300,
            'billing_c3_expiry_context_invalid');
        $data['issued_at'] -= 600;
        $data['expires_at'] -= 600;

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function terminalContext(string $invoice, PostgresTestGuard $guard): string
    {
        $tag = self::target($invoice);
        Identity::require(in_array($tag, ['primary', 'zero', 'cancelled'], true), 'billing_c3_terminal_scope_invalid');
        $template = stream_get_contents(STDIN, 12001);
        Identity::require(is_string($template) && strlen($template) <= 12000, 'billing_c3_terminal_context_invalid');
        $data = json_decode(Crypt::decryptString($template), true, 16, JSON_THROW_ON_ERROR);
        $source = self::source('rejection');
        $target = self::source($tag);
        $audience = hash('sha256', 'silansia.payment.http.v1|'.getenv('SILANSIA_TEST_IDENTITY'));
        Identity::require(($data['v'] ?? null) === 1 && ($data['purpose'] ?? null) === 'command'
            && ($data['operation'] ?? null) === 'submit' && ($data['actor'] ?? null) === $source['family']
            && $data['actor'] === $target['family'] && ($data['audience'] ?? null) === $audience
            && is_string($data['invoice_id'] ?? null) && self::target($data['invoice_id']) === 'rejection'
            && is_int($data['expires_at'] ?? null) && is_int($data['issued_at'] ?? null)
            && $data['expires_at'] - $data['issued_at'] === 300 && $data['issued_at'] <= time() && $data['expires_at'] >= time()
            && preg_match('/\A[a-f0-9]{64}\z/', $data['session'] ?? '') === 1, 'billing_c3_terminal_context_invalid');
        $revision = Observer::read(static function (Connection $db) use ($invoice, $source, $target, $tag, $data): string {
            $root = $db->table('invoices')->where(['id' => $invoice, 'elderly_id' => $target['resident'],
                'bill_to_family_id' => $target['family'], 'status' => $tag === 'cancelled' ? 'cancelled' : 'paid'])->sole();
            Identity::require($root->amount === $target['amount']
                && $db->table('invoices')->where(['id' => $data['invoice_id'], 'elderly_id' => $source['resident'],
                    'current_revision_id' => $data['revision_id'], 'bill_to_family_id' => $source['family']])->exists(),
                'billing_c3_terminal_fixture_mismatch');

            return $root->current_revision_id;
        }, $guard);
        // Negative-only fixture. Keep the issued session, audience and expiry.
        $data['invoice_id'] = $invoice;
        $data['revision_id'] = $revision;
        $data['request_id'] = (string) Str::uuid();
        $data['proof_reference'] = (string) Str::uuid();

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function visibility(string $invoice, string $payment, PostgresTestGuard $guard): array
    {
        self::target($invoice);
        Identity::require(DB::table('payments')->where(['id' => $payment, 'invoice_id' => $invoice])->exists(), 'billing_c3_payment_unbound');

        return Observer::visibility($payment, 'verify_use_granted', $guard);
    }

    public static function stale(string $invoice, string $payment): bool
    {
        Identity::require(self::target($invoice) === 'stale', 'billing_c3_stale_target_invalid');
        $subject = DB::table('proof_scan_subjects')->where(['invoice_id' => $invoice, 'payment_id' => $payment, 'state' => 'CLEAN_ELIGIBLE'])->sole();
        DB::transaction(static function () use ($subject): void {
            DB::select("SELECT public.proof_scan_rescan_or_dispose(?::uuid,?::bigint,'rescan',public.proof_scan_authority())",
                [$subject->id, $subject->state_version]);
        });
        $after = DB::table('proof_scan_subjects')->where('id', $subject->id)->sole();
        Identity::require((int) $after->generation === (int) $subject->generation + 1 && $after->state !== 'CLEAN_ELIGIBLE', 'billing_c3_stale_unverified');

        return true;
    }

    public static function transfer(string $invoice): bool
    {
        Identity::require(self::target($invoice) === 'transfer', 'billing_c3_transfer_target_invalid');
        $s = self::source('transfer');
        $next = Identity::read(Runtime::root().'/c3-sources.json')['next_family'];
        app(ResidentService::class)->reassignFamily(User::findOrFail($s['admin']), Elderly::findOrFail($s['resident']), [
            'family_id' => $next, 'current_family_id' => $s['family'], 'confirm_reassignment' => true,
        ]);
        Identity::require(DB::table('elderlies')->where(['id' => $s['resident'], 'family_id' => $next])->exists()
            && DB::table('invoices')->where(['id' => $invoice, 'bill_to_family_id' => $s['family']])->exists(), 'billing_c3_transfer_unverified');

        return true;
    }

    public static function cancelled(string $invoice): bool
    {
        Identity::require(self::target($invoice) === 'cancelled', 'billing_c3_cancelled_target_invalid');
        $s = self::source('cancelled');
        // Construct only the dedicated negative fixture and let the existing database guards validate the graph.
        DB::transaction(static function () use ($invoice, $s): void {
            $row = DB::table('invoices')->where(['id' => $invoice, 'elderly_id' => $s['resident'], 'status' => 'unpaid'])->lockForUpdate()->sole();
            Identity::require(! DB::table('payments')->where('invoice_id', $invoice)->exists(), 'billing_c3_cancelled_has_payment');
            $time = DB::scalar('SELECT clock_timestamp()::text');
            DB::table('invoices')->where('id', $invoice)->update(['status' => 'cancelled', 'cancelled_at' => $time,
                'cancelled_by' => $s['admin'], 'cancellation_reason' => 'Fixture sintetis C3']);
            $request = (string) Str::uuid();
            DB::table('billing_requests')->insert(['id' => $request, 'actor_id' => $s['admin'], 'request_id' => (string) Str::uuid(),
                'operation' => 'cancel', 'intent_hash' => hash('sha256', 'c3-fixture-cancel|'.$invoice), 'invoice_id' => $invoice,
                'outcome_payment_id' => null, 'outcome_revision_id' => $row->current_revision_id, 'completed_at' => $time]);
            DB::table('billing_actions')->insert(['id' => (string) Str::uuid(), 'billing_request_id' => $request, 'actor_id' => $s['admin'],
                'invoice_id' => $invoice, 'ownership_period_id' => null, 'payment_id' => null, 'revision_id' => $row->current_revision_id,
                'invoice_sequence' => 2, 'action' => 'invoice_cancelled', 'occurred_at' => $time, 'reason_code' => 'fixture_correction',
                'private_note' => null, 'details' => '{"v":1}']);
            DB::select('SELECT public.billing_validate_invoice(?)', [$invoice]);
        });

        return true;
    }
}
