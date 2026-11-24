<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\PaymentCanonical;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentReadiness;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StoredPaymentProof;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\AcademicProofScanClaimRace;
use Tests\Support\AcademicProofScanWorkerCases;
use Tests\Support\BillingIntegrityFixtures;

$owned();
config(['financial_ownership.enabled' => true, 'financial_ownership.cutover_reference' => 'owned-test-only-approval',
    'invoice_issuance.enabled' => true, 'payment_core.enabled' => true]);
$tx = static function (Closure $operation) use ($owned): mixed {
    $owned();
    return DB::transaction(static function () use ($operation) {
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::statement("SET LOCAL statement_timeout='15s'");
        return $operation();
    }, 1);
};
$profile = [
    'version' => 'academic-sim-v1', 'definition_version' => 1,
    'stage_plan' => [['stage_id' => 'synthetic_stage_v1', 'is_simulation' => true]],
    'allowed_versions' => ['adapter_version' => '1', 'scanner_identity' => 'synthetic_mock',
        'engine_id' => 'synthetic_mock', 'engine_version' => '1', 'signature_set_id' => 'not_applicable_simulation',
        'signature_version' => 'not_applicable_simulation', 'auth_scheme_version' => 'trusted_in_process_v1',
        'key_epoch_reference' => 'not_applicable_owned_runtime'],
    'retry_profile' => ['max_attempts' => 2, 'adapter_timeout_seconds' => 5, 'lease_seconds' => 30, 'retry_due_seconds' => 1],
    'freshness_profile' => ['verdict_seconds' => 120], 'resource_profile' => ['worker_concurrency' => 1, 'dispatcher' => 'manual_one_shot'],
];
$policy = $tx(fn () => DB::scalar('SELECT public.proof_scan_create_policy(?::jsonb, public.proof_scan_authority())',
    [json_encode($profile, JSON_THROW_ON_ERROR)]));
$epoch = 0;
$incarnation = (string) Str::uuid();
$setAdmission = static function (string $admission) use ($tx, $policy, $incarnation, &$epoch, $academicEvidence): void {
    $epoch = (int) $tx(fn () => DB::scalar('SELECT public.proof_scan_set_head(?, ?, ?, ?, public.proof_scan_authority())',
        [$policy, $incarnation, $epoch, $admission]));
    $academicEvidence('head', ['admission' => $admission, 'epoch' => $epoch]);
};
$setAdmission('active');
$fixtures = new class
{
    use BillingIntegrityFixtures {
        billingGraph as public graph;
    }
};
$submit = static function (string $bytes) use ($owned, $fixtures, $assert, $academicEvidence): string {
    $owned();
    $g = $fixtures->graph();
    $ref = (string) Str::uuid();
    $family = $g['p']['family'];
    $invoice = $g['invoice']['id'];
    $store = new class($bytes, $ref, $family, $invoice) extends PaymentProofStore
    {
        private readonly StoredPaymentProof $proof;

        public function __construct(private string $bytes, private string $ref, private string $family, private string $invoice)
        {
            $mime = getimagesizefromstring($bytes)['mime'] ?? null;
            if ($mime !== 'image/png') {
                throw new RuntimeException('synthetic_fixture_png_required');
            }
            $this->proof = new StoredPaymentProof("payment-proofs/{$ref}/object.png", hash('sha256', $bytes), strlen($bytes), $mime);
        }

        public function resolve(string $reference, string $submitter, string $invoice): StoredPaymentProof
        {
            if ($reference !== $this->ref || $submitter !== $this->family || $invoice !== $this->invoice) {
                throw new PaymentFailure('invalid_proof');
            }
            return $this->proof;
        }

        public function assertStored(StoredPaymentProof $proof, string $submitter, string $invoice): void
        {
            if ($this->resolve($this->ref, $submitter, $invoice)->row() !== $proof->row()
                || ! hash_equals(hash('sha256', $this->bytes), $proof->sha256)) {
                throw new PaymentFailure('proof_integrity_failure');
            }
        }
    };
    $owned();
    $service = new PaymentService(app(PaymentReadiness::class), $store, app(PaymentCanonical::class), app(PaymentPolicy::class));
    Auth::setUser(User::findOrFail($family));
    try {
        $result = $service->submit(new PaymentCommand([
            'operation' => 'submit', 'actor_id' => $family, 'invoice_id' => $invoice,
            'revision_id' => $g['revision']['id'], 'request_id' => (string) Str::uuid(),
            'proof_reference' => $ref, 'confirm' => true,
        ]));
    } finally {
        Auth::forgetUser();
    }
    $owned();
    $graph = DB::selectOne("SELECT p.status, q.state AS subject_state, q.generation, q.state_version, j.id, j.state AS job_state,
        j.attempt_count, (SELECT count(*) FROM public.proof_scan_audit_events e WHERE e.subject_id=q.id)::int AS audit_count
        FROM public.payments p JOIN public.proof_scan_subjects q ON q.payment_id=p.id
        JOIN public.proof_scan_jobs j ON j.subject_id=q.id WHERE p.id=?", [$result->paymentId]);
    $assert($graph && $graph->status === 'pending' && $graph->subject_state === 'QUEUED' && $graph->job_state === 'READY'
        && (int) $graph->generation === 1 && (int) $graph->state_version === 1 && (int) $graph->attempt_count === 0
        && $graph->audit_count === 2, 'academic_j1_initial_graph');
    $academicEvidence('j1_fixture', ['payment' => 'pending', 'subject' => 'QUEUED', 'job' => 'READY', 'generation' => 1, 'audit_count' => 2]);
    return $graph->id;
};
$invokeCommand = static function () use ($kernel, $assert): array {
    $buffer = new BufferedOutput;
    $exit = $kernel->call('silansia:academic-proof-scan', [], $buffer);
    $lines = preg_split('/\R/', trim($buffer->fetch()));
    $assert(count($lines) === 2 && $lines[0] === AcademicProofScanner::LABEL, 'academic_command_label_or_format');
    $result = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
    $assert(is_array($result) && ($result['is_simulation'] ?? null) === true
        && array_diff(array_keys($result), ['is_simulation', 'status', 'phase', 'verdict', 'historical_ack']) === [], 'academic_command_output');
    $expected = in_array($result['status'], ['accepted', 'expired', 'idle', 'busy'], true) ? 0 : 1;
    $assert($exit === $expected, 'academic_command_exit');
    return [...$result, 'exit' => $exit];
};
$cases = new AcademicProofScanWorkerCases($owned, $submit, $setAdmission, $invokeCommand, $academicEvidence);
if ($academicCase === 'concurrentClaim') {
    $cases->concurrentClaim(fn (array $jobs) => (new AcademicProofScanClaimRace($guard, $owned, $academicEvidence))->run($jobs));
} else {
    $cases->run($academicCase);
}
$record['academic_assertions_passed'] = true;
