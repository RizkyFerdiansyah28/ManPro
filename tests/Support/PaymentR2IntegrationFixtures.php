<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Elderly;
use App\Models\ServiceEnrollment;
use App\Models\User;
use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\BillingTime;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceIssueCommand;
use App\Services\Billing\PaymentProofEnvironment;
use App\Services\ResidentService;
use Database\Seeders\ServiceClassSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;

final class PaymentR2IntegrationFixtures
{
    use BillingIntegrityFixtures;

    public function prepare(string $scenario): array
    {
        Identity::require($scenario === getenv('SILANSIA_05E_SCENARIO'), 'scenario_mismatch');
        $accounts = [];
        $password = 'R2e!'.bin2hex(random_bytes(24));
        foreach (['a' => ['keluarga', true], 'b' => ['keluarga', true], 'admin' => ['admin', true],
            'inactive' => ['admin', false], 'nurse' => ['perawat', true]] as $tag => [$role, $active]) {
            $accounts[$tag] = User::factory()->create(['name' => 'Simulasi R2 '.$tag, 'role' => $role, 'is_active' => $active,
                'email' => 'r2e-'.Str::uuid().'@example.test', 'password' => $password]);
        }
        (new ServiceClassSeeder)->run();
        $sources = ['primary' => $this->source($accounts['admin'], $accounts['a'], $scenario === 'family' ? 'Bayar' : 'Uji')];
        if (in_array($scenario, ['family', 'security'], true)) {
            $sources['foreign'] = $this->source($accounts['admin'], $accounts['b'], 'Keluarga B');
        }
        if ($scenario === 'family') {
            $sources['zero'] = $this->source($accounts['admin'], $accounts['a'], 'Nol', '0.00');
        }
        if ($scenario === 'late') {
            $sources['next'] = $this->source($accounts['admin'], $accounts['a'], 'Tujuan berikutnya');
        }
        if ($scenario === 'ordering') {
            foreach (['get-visible', 'get-rollback', 'get-unknown', 'verify-visible', 'verify-rollback', 'verify-unknown'] as $mode) {
                $sources[$mode] = $this->source($accounts['admin'], $accounts['a'], $mode);
            }
        }
        if ($scenario === 'security') {
            $sources['transfer'] = $this->source($accounts['admin'], $accounts['a'], 'Transfer');
            $resident = Elderly::findOrFail($sources['transfer']['resident']);
            app(ResidentService::class)->reassignFamily($accounts['admin'], $resident, [
                'family_id' => $accounts['b']->id, 'current_family_id' => $accounts['a']->id, 'confirm_reassignment' => true,
            ]);
            $sources['legacy'] = $this->source($accounts['admin'], $accounts['a'], 'Legacy', legacy: true);
        }
        if ($scenario !== 'no-policy') {
            $this->createPolicy();
        }
        $proofType = match ($scenario) {
            'held' => 'unknown',
            'infected' => 'infected',
            default => 'clean',
        };
        Identity::write(Runtime::root().'/fixture-targets.json', ['scenario' => $scenario,
            'invoices' => array_column($sources, 'invoice'), 'accounts' => array_map(fn (User $u) => $u->id, $accounts)]);

        // Credentials and PNG are passed by stdin to the browser driver. Never persist or log them.
        return ['ok' => true, 'sources' => $sources, 'accounts' => array_map(fn (User $u) => $u->only('id', 'email'), $accounts),
            'password' => $password, 'proof_type' => $proofType, 'proof' => base64_encode(AcademicProofScanner::fixtureBytes($proofType))];
    }

    private function source(User $admin, User $family, string $label, string $price = '3100.00', bool $legacy = false): array
    {
        $start = (new DateTimeImmutable('first day of last month', new DateTimeZone('Asia/Jakarta')))->setTime(0, 0);
        [$elderly, $enrollment] = $this->sourceRecords($admin, $family, $label, $price, $start);
        $month = $start->format('Y-m-d');
        if ($legacy) {
            $invoice = (string) Str::uuid();
            DB::table('invoices')->insert(['id' => $invoice, 'number' => 'R2E-LEGACY-'.$invoice, 'elderly_id' => $elderly->id,
                'service_enrollment_id' => $enrollment->id, 'billing_month' => $month, 'amount' => $price, 'status' => 'unpaid',
                'due_date' => $start->modify('+1 month +7 days')->format('Y-m-d'), 'grace_period_ends_on' => $start->modify('+1 month +10 days')->format('Y-m-d')]);
        } else {
            $issuance = app(InvoiceIssuanceService::class);
            $preview = $issuance->preview($admin->id, $elderly->id, $month);
            $invoice = $issuance->issue(new InvoiceIssueCommand($admin->id, $elderly->id, $month,
                (string) Str::uuid(), $preview->confirmation))->invoiceId;
        }

        return ['invoice' => $invoice, 'resident' => $elderly->id, 'label' => $elderly->name];
    }

    public function sourceRecords(User $admin, User $family, string $label, string $price, DateTimeImmutable $start): array
    {
        return DB::transaction(function () use ($admin, $family, $label, $price, $start): array {
            $elderly = Elderly::create(['family_id' => $family->id, 'name' => 'Simulasi '.$label.' '.substr((string) Str::uuid(), 0, 8),
                'birth_date' => '1950-01-01', 'is_active' => true]);
            $enrollment = ServiceEnrollment::create(['elderly_id' => $elderly->id,
                'service_class_id' => DB::table('service_classes')->where('code', 'vip')->value('id'),
                'monthly_price' => $price, 'started_at' => $start->format('Y-m-d H:i:sP')]);
            $from = $start->modify('-1 month')->format('Y-m-d H:i:sP');
            // Same dated ledger pattern as InvoiceIssuanceFeatureTestCase::source.
            $period = ['id' => (string) Str::uuid(), 'elderly_id' => $elderly->id, 'family_id' => $family->id,
                'previous_period_id' => null, 'effective_from' => $from, 'effective_to' => null,
                'recorded_by' => $admin->id, 'recorded_at' => $from, 'source' => 'cutover_capture'];
            DB::table('financial_ownership_periods')->insert($period);
            $this->billingOwnershipAction($period, 'ownership_captured', $admin->id, $from);

            return [$elderly, $enrollment];
        });
    }

    public static function policyProfile(): array
    {
        return [
            'version' => 'academic-sim-v1', 'definition_version' => 1,
            'stage_plan' => [['stage_id' => 'synthetic_stage_v1', 'is_simulation' => true]],
            'allowed_versions' => ['adapter_version' => '1', 'scanner_identity' => 'synthetic_mock',
                'engine_id' => 'synthetic_mock', 'engine_version' => '1', 'signature_set_id' => 'not_applicable_simulation',
                'signature_version' => 'not_applicable_simulation', 'auth_scheme_version' => 'trusted_in_process_v1',
                'key_epoch_reference' => 'not_applicable_owned_runtime'],
            'retry_profile' => ['max_attempts' => 2, 'adapter_timeout_seconds' => 5, 'lease_seconds' => 30, 'retry_due_seconds' => 1],
            'freshness_profile' => ['verdict_seconds' => 120], 'resource_profile' => ['worker_concurrency' => 1, 'dispatcher' => 'manual_one_shot'],
        ];
    }

    public function createPolicy(): void
    {
        $connection = DB::connection();
        Identity::require($connection->transactionLevel() === 0, 'fixture_policy_outer_transaction');

        $policy = $connection->transaction(static fn () => $connection->scalar(
            'SELECT public.proof_scan_create_policy(?::jsonb, public.proof_scan_authority())',
            [json_encode(self::policyProfile(), JSON_THROW_ON_ERROR)]));

        // The SQL guard requires a policy committed by an earlier transaction.
        Identity::require($connection->transactionLevel() === 0, 'fixture_policy_commit_unconfirmed');
        $connection->transaction(static function () use ($connection, $policy): void {
            $epoch = $connection->scalar('SELECT public.proof_scan_set_head(?,?,?,?,public.proof_scan_authority())',
                [$policy, (string) Str::uuid(), 0, 'active']);
            Identity::require((int) $epoch === 1, 'fixture_policy_not_active');
        });
    }

    public static function target(string $invoice, ?string $case = null): array
    {
        $fixture = Identity::read(Runtime::root().'/fixture-targets.json');
        Identity::require(BillingTime::uuid($invoice) && in_array($invoice, $fixture['invoices'], true)
            && ($case === null || $fixture['scenario'] === $case), 'fixture_target_not_owned');

        return $fixture;
    }

    public static function scan(string $invoice, string $payment): array
    {
        $fixture = self::target($invoice);
        Identity::require(BillingTime::uuid($payment), 'invalid_fixture_payment');
        $expected = match ($fixture['scenario']) {
            'held' => ['unknown', 'UNKNOWN_HELD'],
            'infected' => ['infected', 'INFECTED_HELD'],
            default => ['clean', 'CLEAN_ELIGIBLE'],
        };
        return self::scanTarget($invoice, $payment, $expected, $fixture['scenario'] !== 'scanner-off');
    }

    public static function scanTarget(string $invoice, string $payment, array $expected, bool $enabled = true): array
    {
        $queued = DB::select("SELECT q.id,q.generation,q.payment_id FROM proof_scan_subjects q JOIN proof_scan_jobs j
            ON j.subject_id=q.id AND j.generation=q.generation WHERE q.state='QUEUED' AND j.state='READY'");
        Identity::require(count($queued) === 1 && $queued[0]->payment_id === $payment
            && DB::table('payments')->where(['id' => $payment, 'invoice_id' => $invoice, 'status' => 'pending'])->exists(), 'scan_ready_target_not_unique');
        Identity::require(getenv('SILANSIA_ACADEMIC_PROOF_SCAN') === ($enabled ? '1' : '0'), 'scanner_gate_mismatch');
        $output = new BufferedOutput;
        $exit = app(Kernel::class)->call('silansia:academic-proof-scan', [], $output);
        $lines = preg_split('/\R/', trim($output->fetch()));
        Identity::require(count($lines) === 2 && $lines[0] === AcademicProofScanner::LABEL, 'scanner_label_missing');
        $result = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
        $after = DB::table('proof_scan_subjects')->where('id', $queued[0]->id)->sole();
        if (! $enabled) {
            Identity::require($exit !== 0 && ($result['is_simulation'] ?? null) === true && $after->state === 'QUEUED', 'scanner_gate_did_not_deny');

            return ['accepted' => false, 'state' => $after->state];
        }
        Identity::require($exit === 0 && ($result['is_simulation'] ?? null) === true && $result['status'] === 'accepted'
            && $result['verdict'] === $expected[0] && $after->state === $expected[1]
            && $after->generation === $queued[0]->generation, 'scanner_target_or_verdict_mismatch');

        return ['accepted' => true, 'state' => $after->state];
    }

    public static function holdPreviousGeneration(string $invoice, string $payment): void
    {
        $fixture = self::target($invoice);
        Identity::require(in_array($fixture['scenario'], ['stale', 'stale-envelope', 'admin-replay'], true), 'rescan_fixture_not_owned');
        $subject = self::cleanSubject($invoice, $payment, $fixture['scenario'] === 'admin-replay' ? 'verified' : 'pending');
        DB::transaction(static function () use ($subject): void {
            DB::select("SELECT public.proof_scan_rescan_or_dispose(?::uuid,?::bigint,'rescan',public.proof_scan_authority())",
                [$subject->id, $subject->state_version]);
        });
        $after = DB::table('proof_scan_subjects')->where('id', $subject->id)->sole();
        Identity::require((int) $after->generation === (int) $subject->generation + 1 && $after->state !== 'CLEAN_ELIGIBLE',
            'stale_fixture_not_held');
    }

    public static function prepareScannerDenial(string $invoice, string $payment): array
    {
        $fixture = self::target($invoice);
        $scenario = $fixture['scenario'];
        Identity::require(in_array($scenario, ['missing-verdict', 'stale-envelope', 'wrong-subject', 'wrong-generation'], true)
            && BillingTime::uuid($payment) && getenv('SILANSIA_ACADEMIC_PROOF_SCAN') === '1'
            && DB::table('payments')->where(['id' => $payment, 'invoice_id' => $invoice, 'status' => 'pending'])->exists(),
            'scanner_denial_fixture_not_owned');
        $subject = DB::table('proof_scan_subjects')->where('payment_id', $payment)->sole();
        $job = DB::table('proof_scan_jobs')->where(['subject_id' => $subject->id, 'generation' => $subject->generation, 'state' => 'READY'])->sole();
        $assignment = DB::transaction(static fn () => json_decode(DB::scalar(
            'SELECT public.proof_scan_claim_attempt(?::uuid,1,public.proof_scan_authority(),public.proof_scan_authority())',
            [$job->id]
        ), true, flags: JSON_THROW_ON_ERROR));
        Identity::require(($assignment['is_simulation'] ?? null) === true && $assignment['subject_id'] === $subject->id,
            'scanner_denial_assignment_invalid');
        DB::transaction(static fn () => DB::select(
            "SELECT public.proof_scan_start_or_expire_attempt(?::uuid,'start',public.proof_scan_authority())", [$assignment['attempt_id']]
        ));
        $before = PaymentR2IntegrationObserver::snapshot($invoice);
        if ($scenario === 'missing-verdict') {
            Identity::require(! DB::table('proof_scan_verdicts')->where('attempt_id', $assignment['attempt_id'])->exists(),
                'missing_verdict_control_invalid');

            return ['missing_current_verdict' => true];
        }
        // Negative-only contract probes. A successful mutation is a failure;
        // the deliberate exception below also prevents any unexpected commit.
        unset($assignment['lease_deadline'], $assignment['adapter_timeout_seconds']);
        $candidate = [...$assignment, 'result_id' => $assignment['attempt_id'], 'verdict' => 'unknown', 'reason_code' => 'simulation_unknown',
            'observed_at' => DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')"),
            'engine_id' => 'synthetic_mock', 'engine_version' => '1', 'signature_set_id' => 'not_applicable_simulation',
            'signature_version' => 'not_applicable_simulation', 'auth_scheme_version' => 'trusted_in_process_v1',
            'key_epoch_reference' => 'not_applicable_owned_runtime'];
        if ($scenario === 'wrong-subject') {
            $candidate['subject_id'] = (string) Str::uuid();
        } elseif ($scenario === 'wrong-generation') {
            $candidate['generation']++;
        } else {
            Identity::require($candidate['generation'] > 1, 'previous_generation_required');
            $candidate['generation']--;
        }
        $denied = false;
        try {
            DB::transaction(static function () use ($candidate): never {
                DB::scalar('SELECT public.proof_scan_accept_result(?::jsonb,public.proof_scan_authority())',
                    [json_encode($candidate, JSON_THROW_ON_ERROR)]);
                throw new \LogicException('invalid_result_unexpectedly_accepted');
            }, 1);
        } catch (PDOException $error) {
            $denied = ($error->errorInfo[0] ?? null) === '23514'
                && str_contains($error->errorInfo[2] ?? '', 'constraint "pscan_result_binding"');
        }
        $previous = json_encode($before, JSON_THROW_ON_ERROR);
        $after = json_encode(PaymentR2IntegrationObserver::snapshot($invoice), JSON_THROW_ON_ERROR);
        Identity::require($denied && hash_equals($previous, $after), 'scanner_binding_denial_not_proven');

        return ['binding_rejected' => true, 'graph_unchanged' => true];
    }

    public static function expireContext(#[\SensitiveParameter] string $token): string
    {
        $fixture = Identity::read(Runtime::root().'/fixture-targets.json');
        Identity::require($fixture['scenario'] === 'expired' && $fixture['scenario'] === getenv('SILANSIA_05E_SCENARIO')
            && $token !== '' && strlen($token) <= 12000, 'expiry_fixture_not_owned');
        $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        Identity::require(($data['v'] ?? null) === 1 && in_array($data['actor'] ?? null, $fixture['accounts'], true)
            && is_int($data['issued_at'] ?? null) && is_int($data['expires_at'] ?? null)
            && $data['expires_at'] - $data['issued_at'] === 300, 'expiry_context_not_current_fixture');
        Identity::require(($data['purpose'] ?? null) === 'recovery' || (($data['purpose'] ?? null) === 'command'
            && in_array($data['invoice_id'] ?? null, $fixture['invoices'], true)), 'expiry_context_target_mismatch');
        $data['issued_at'] -= 600;
        $data['expires_at'] -= 600;

        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function hideProof(string $invoice, string $payment): void
    {
        self::target($invoice, 'missing');
        self::cleanSubject($invoice, $payment);
        $proof = DB::table('payments')->where('id', $payment)->sole()->proof_path;
        Identity::require(preg_match('#\Apayment-proofs/([0-9a-f-]{36})/object\.png\z#', $proof, $match) === 1
            && BillingTime::uuid($match[1]), 'missing_fixture_proof_invalid');
        $object = Runtime::path(app(PaymentProofEnvironment::class)->root().'/'.$match[1].'/object.png');
        $held = Runtime::path(Runtime::root().'/artifacts/missing-fixture-parked.png');
        Identity::require(is_file($object) && ! file_exists($held) && ! is_link($held) && rename($object, $held), 'fixture_object_park_failed');
    }

    private static function cleanSubject(string $invoice, string $payment, string $status = 'pending'): object
    {
        Identity::require(BillingTime::uuid($payment)
            && DB::table('payments')->where(['id' => $payment, 'invoice_id' => $invoice, 'status' => $status])->exists(), 'negative_fixture_state_mismatch');

        return DB::table('proof_scan_subjects')->where(['payment_id' => $payment, 'state' => 'CLEAN_ELIGIBLE'])->sole();
    }
}
