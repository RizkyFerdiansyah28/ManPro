<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ServiceClass;
use App\Models\User;
use App\Services\Billing\AcademicProofScanner;
use App\Services\Billing\BillingTime;
use App\Support\CanonicalServiceClasses;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FrameworkRuntimeIsolation as Runtime;
use Tests\Support\PaymentR2IntegrationIdentity as Identity;
use Throwable;
use WeakReference;

final class BillingMinimumDemo
{
    public const CHILD_CLAIM = 'owned-functional-demo';

    public const STAGES = ['demo_migrate', 'demo_fixture', 'demo_login', 'demo_preview', 'demo_issue', 'demo_submit',
        'demo_scan', 'demo_proof', 'demo_verify', 'demo_paid'];

    private const ACTIVE_CONFIG = [
        'financial_ownership.enabled' => true,
        'financial_ownership.cutover_reference' => 'owned-minimum-demo',
        'invoice_issuance.enabled' => true,
        'payment_core.enabled' => true,
        'payment_http.enabled' => true,
        'payment_http.synthetic_validation' => true,
    ];

    private const AMOUNT = '3100.00';

    public static function assertInvocation(string $root, string $run): void
    {
        Identity::require(Runtime::root() === $root && basename($root) === 'silansia-p94e1-'.$run
            && getenv('SILANSIA_BILLING_C2_RUN') === $run, 'billing_demo_invocation_required');
        $claim = Runtime::path($root.'/child.claim');
        Identity::require(is_file($claim) && filesize($claim) === strlen(self::CHILD_CLAIM)
            && file_get_contents($claim) === self::CHILD_CLAIM, 'billing_demo_invocation_required');
    }

    public static function assertTargetIdentity(array $identity, string $root, string $run, string $cache): void
    {
        $port = $identity['connection_port'] ?? null;
        Identity::require(($identity['runtime_root'] ?? null) === $root
            && ($identity['database'] ?? null) === Identity::databaseFor($run)
            && ($identity['owner'] ?? null) === 'silansia_c2_'.$run && ($identity['host'] ?? null) === '127.0.0.1'
            && in_array($identity['address'] ?? null, ['127.0.0.1', '127.0.0.1/32'], true)
            && ($identity['port'] ?? null) === $port && is_string($port) && ctype_digit($port)
            && (int) $port > 1024 && (int) $port <= 65535
            && ! in_array($port, ['5432', '5187', '8000'], true) && ($identity['cache'] ?? null) === $cache,
            'billing_demo_target_mismatch');
    }

    public static function validateResult(mixed $result): array
    {
        Identity::require(is_array($result) && array_keys($result) === ['status', 'stage']
            && in_array($result['stage'], self::STAGES, true)
            && ($result['status'] === 'FAIL' || ($result['status'] === 'PASS' && $result['stage'] === 'demo_paid')),
            'billing_demo_result_invalid');

        return $result;
    }

    public static function safeReason(string $reason): string
    {
        return in_array($reason, ['billing_c2_invalid_command', 'billing_c2_child_unverified', 'billing_demo_invocation_required',
            'billing_demo_target_mismatch', 'billing_demo_result_invalid', 'billing_demo_runtime_failed', 'billing_demo_outcome_unconfirmed',
            'billing_demo_ownership_unverified',
            'billing_c2_connection_release_unverified', 'receipt_mismatch', 'runtime_context_missing', 'source_pin_mismatch'], true)
            ? $reason : 'billing_demo_blocked';
    }

    public static function run(Application $app, PostgresTestGuard $guard, #[\SensitiveParameter] string $password,
        Closure $assertCluster, string &$stage): array
    {
        $stage = 'demo_migrate';
        $root = Runtime::root();
        $run = getenv('SILANSIA_BILLING_C2_RUN') ?: '';
        self::assertInvocation($root, $run);
        $identity = $guard->identity;
        self::assertTargetIdentity($identity, $root, $run, BillingC2Preflight::assertCachePreconditions());
        $verifyReceipt = static function () use ($root, $run, $identity): void {
            Identity::verifyReceipt(Identity::read(Runtime::path($root.'/database-receipt.json')), $run,
                $identity, getenv('SILANSIA_RUNTIME_OWNER'));
        };
        $verifyReceipt();
        BillingC2Preflight::assertChildSettings();
        $assertCluster();
        $config = $app['config'];
        Identity::require($app->environment('testing') && ! $app->configurationIsCached()
            && $app->publicPath() === Runtime::path($root.'/public', true)
            && $config->get('database.default') === 'silansia_testing'
            && $config->get('database.connections') === [] && $app['db']->getConnections() === [],
            'billing_demo_invocation_required');
        $original = [];
        foreach (self::ACTIVE_CONFIG as $key => $value) {
            $original[$key] = $config->get($key);
            Identity::require($original[$key] === ($key === 'financial_ownership.cutover_reference' ? null : false),
                'billing_demo_invocation_required');
        }

        $primaryPdo = null;
        try {
            Identity::require(putenv('SILANSIA_TEST_IDENTITY='.json_encode($identity, JSON_THROW_ON_ERROR)),
                'billing_demo_invocation_required');
            $config->set('database.connections', ['silansia_testing' => [
                'driver' => 'pgsql', 'url' => null, 'host' => $identity['host'], 'port' => $identity['connection_port'],
                'database' => $identity['database'], 'username' => $identity['owner'], 'password' => $password,
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable', 'timezone' => 'UTC',
                'options' => [PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 5],
            ]]);
            PaymentR2IntegrationRuntime::checkDatabase($app, DB::connection(), $guard);
            $primaryPdo = WeakReference::create(DB::connection()->getPdo());
            $checkOwned = static function () use ($app, $guard, $assertCluster, $verifyReceipt): void {
                try {
                    $assertCluster();
                    $verifyReceipt();
                    $guard->assertSafe($app, DB::connection());
                } catch (Throwable) {
                    throw new LogicException('billing_demo_ownership_unverified');
                }
            };
            PaymentR2IntegrationRuntime::migrate($app, $guard);
            $checkOwned();

            // Activate only in this bootstrapped, owned application, after schema verification.
            $config->set(self::ACTIVE_CONFIG);
            Identity::require(putenv('SILANSIA_ACADEMIC_PROOF_SCAN=1'), 'billing_demo_invocation_required');
            $app->bind(PreventRequestForgery::class, static fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
            $result = self::scenarioResult(static function () use ($app, $guard, $checkOwned, &$stage): void {
                self::scenario($app, $guard, $checkOwned, $stage);
            }, $stage);
            $checkOwned();

            return $result;
        } catch (Throwable $error) {
            if ($error instanceof LogicException && self::safeReason($error->getMessage()) === $error->getMessage()) {
                throw $error;
            }

            throw new LogicException('billing_demo_runtime_failed');
        } finally {
            $app['db']->purge('silansia_testing');
            $config->set('database.connections', []);
            $config->set($original);
            putenv('SILANSIA_TEST_IDENTITY');
            putenv('SILANSIA_ACADEMIC_PROOF_SCAN=false');
            Identity::require($primaryPdo?->get() === null, 'billing_c2_connection_release_unverified');
        }
    }

    /** Return a definitive scenario failure so the C2 owner can run its guarded cleanup. */
    public static function scenarioResult(Closure $scenario, string &$stage): array
    {
        $status = 'PASS';
        try {
            $scenario();
        } catch (Throwable $error) {
            // Guard failures, unclear outcomes, and other unsanctioned logic failures must escape.
            if ($error instanceof LogicException && ! in_array($error->getMessage(), [
                'billing_demo_assertion_failed', 'fixture_policy_not_active', 'fixture_policy_outer_transaction',
                'fixture_policy_commit_unconfirmed',
            ], true)) {
                throw $error;
            }
            $status = 'FAIL';
        }

        return self::validateResult(['status' => $status, 'stage' => $stage]);
    }

    private static function scenario(Application $app, PostgresTestGuard $guard, Closure $checkOwned, string &$stage): void
    {
        $stage = 'demo_fixture';
        $checkOwned();
        $password = 'Synth!'.bin2hex(random_bytes(24));
        $admin = self::account('admin', $password);
        $family = self::account('keluarga', $password);
        ServiceClass::create(['code' => 'vip', 'name' => 'VIP Simulasi', ...CanonicalServiceClasses::DEFINITIONS['vip'],
            'monthly_price' => self::AMOUNT, 'is_active' => true]);
        $start = (new DateTimeImmutable('first day of last month', new DateTimeZone('Asia/Jakarta')))->setTime(0, 0);
        $fixtures = new PaymentR2IntegrationFixtures;
        [$resident] = $fixtures->sourceRecords($admin, $family, 'Billing', self::AMOUNT, $start);
        $fixtures->createPolicy();
        self::assertEmptyGraph($guard);

        $stage = 'demo_login';
        $checkOwned();
        $adminSession = new BillingMinimumDemoSession($app);
        $familySession = new BillingMinimumDemoSession($app);
        $adminSession->login($admin, $password, proveCsrf: true);
        $familySession->login($family, $password);
        self::check($adminSession->sessionId() !== $familySession->sessionId());

        $stage = 'demo_preview';
        $invoicePath = '/admin/billing/invoices';
        $source = ['elderly_id' => $resident->id, 'billing_month' => $start->format('Y-m-d')];
        $checkOwned();
        $preview = $adminSession->json('POST', $invoicePath.'/preview', 200, $source);
        self::check(($preview['issued'] ?? null) === false && ($preview['decision'] ?? null) === 'ISSUE'
            && ($preview['calculation']['amount'] ?? null) === self::AMOUNT
            && ($preview['calculation']['financial_owner_id'] ?? null) === $family->id);
        self::assertEmptyGraph($guard);

        $stage = 'demo_issue';
        $checkOwned();
        $issued = $adminSession->json('POST', $invoicePath, 201, [...$source, 'confirm' => true,
            'request_id' => self::field($preview['confirmation'] ?? [], 'request_id'),
            'confirmation_token' => self::field($preview['confirmation'] ?? [], 'token')]);
        $invoice = self::field($issued, 'invoice_id');
        $revision = self::field($issued, 'revision_id');
        self::check(BillingTime::uuid($invoice) && BillingTime::uuid($revision) && ($issued['issued'] ?? null) === true
            && ($issued['replayed'] ?? null) === false && ($issued['amount'] ?? null) === self::AMOUNT);
        $familyPath = '/keluarga/billing/invoices/'.$invoice;
        self::assertFamilyState($familySession, $familyPath, $invoice, 'unpaid');

        $stage = 'demo_submit';
        $bytes = AcademicProofScanner::fixtureBytes('clean');
        $upload = Runtime::path(ini_get('upload_tmp_dir').DIRECTORY_SEPARATOR.'demo-clean.png');
        Identity::writeNew($upload, $bytes);
        $context = $familySession->json('GET', $familyPath.'/payment-context', 200);
        $checkOwned();
        $submitted = $familySession->json('POST', $familyPath.'/payments', 201,
            ['confirm' => '1', 'command_context' => self::field($context, 'command_context')],
            new UploadedFile($upload, 'demo-clean.png', 'image/png', null, true));
        $payment = self::field($submitted, 'payment_id');
        self::assertPaymentFact($submitted, $invoice, $revision, $payment, 'submission_recorded');
        self::assertFamilyState($familySession, $familyPath, $invoice, 'unpaid', $payment, 'pending');
        self::observe($guard, $invoice, $revision, $payment, $admin->id, $family->id, 'QUEUED');

        $stage = 'demo_scan';
        $checkOwned();
        $output = new BufferedOutput;
        $exit = $app->make(ConsoleKernel::class)->call('silansia:academic-proof-scan', [], $output);
        [$label, $line] = array_pad(preg_split('/\R/', trim($output->fetch()), 2), 2, '');
        $scan = json_decode(trim($line), true);
        if (($scan['status'] ?? null) === 'commit_unknown') {
            throw new LogicException('billing_demo_outcome_unconfirmed');
        }
        self::check($exit === 0 && trim($label) === AcademicProofScanner::LABEL && ($scan['is_simulation'] ?? null) === true
            && ($scan['status'] ?? null) === 'accepted' && ($scan['verdict'] ?? null) === 'clean');
        self::observe($guard, $invoice, $revision, $payment, $admin->id, $family->id, 'CLEAN_ELIGIBLE');

        $stage = 'demo_proof';
        $checkOwned();
        $proof = $adminSession->request('GET', '/billing/invoices/'.$invoice.'/payments/'.$payment.'/proof', 200);
        $proofBytes = $proof->getContent();
        self::check($proof->headers->get('Content-Type') === 'image/png' && is_string($proofBytes)
            && $proof->headers->hasCacheControlDirective('no-store') && hash_equals($bytes, $proofBytes));

        $stage = 'demo_verify';
        $review = $invoicePath.'/'.$invoice.'/payments/'.$payment;
        $context = $adminSession->json('GET', $review.'/review-context?operation=verify', 200);
        $checkOwned();
        $verified = $adminSession->json('POST', $review.'/verify', 200,
            ['confirm' => true, 'bank_receipt_checked' => true, 'command_context' => self::field($context, 'command_context')]);
        self::assertPaymentFact($verified, $invoice, $revision, $payment, 'verification_recorded');

        $stage = 'demo_paid';
        self::assertFamilyState($familySession, $familyPath, $invoice, 'paid', $payment, 'verified');
        self::observe($guard, $invoice, $revision, $payment, $admin->id, $family->id, 'CLEAN_ELIGIBLE', paid: true);
    }

    private static function account(string $role, #[\SensitiveParameter] string $password): User
    {
        return User::factory()->create(['name' => 'Simulasi '.$role, 'email' => 'demo-'.$role.'-'.Str::uuid().'@example.test',
            'password' => $password, 'role' => $role, 'is_active' => true]);
    }

    private static function assertEmptyGraph(PostgresTestGuard $guard): void
    {
        PaymentR2IntegrationObserver::read(static function (Connection $db): void {
            foreach (['invoices', 'invoice_revisions', 'payments', 'billing_requests', 'proof_scan_subjects',
                'proof_scan_jobs', 'proof_scan_attempts', 'proof_scan_verdicts'] as $table) {
                self::check($db->table($table)->doesntExist());
            }
            self::check($db->table('billing_actions')->whereNotNull('invoice_id')->doesntExist());
        }, $guard);
    }

    private static function observe(PostgresTestGuard $guard, string $invoice, string $revision, string $payment,
        string $admin, string $family, string $scannerState, bool $paid = false): void
    {
        PaymentR2IntegrationObserver::read(static function (Connection $db) use ($invoice, $revision, $payment, $admin, $family, $scannerState, $paid): void {
            $bill = $db->table('invoices')->sole();
            $version = $db->table('invoice_revisions')->sole();
            $record = $db->table('payments')->sole();
            self::check($bill->id === $invoice && $bill->current_revision_id === $revision && $bill->bill_to_family_id === $family
                && $bill->amount === self::AMOUNT && $bill->status === ($paid ? 'paid' : 'unpaid') && ($bill->paid_at !== null) === $paid
                && $version->id === $revision && $version->invoice_id === $invoice && $version->amount === self::AMOUNT
                && $record->id === $payment && $record->invoice_id === $invoice && $record->invoice_revision_id === $revision
                && $record->amount === self::AMOUNT && $record->submitted_by === $family
                && $record->status === ($paid ? 'verified' : 'pending') && $record->verified_by === ($paid ? $admin : null)
                && ($record->verified_at !== null) === $paid);
            $subject = $db->table('proof_scan_subjects')->sole();
            $job = $db->table('proof_scan_jobs')->sole();
            self::check($subject->payment_id === $payment && $subject->invoice_id === $invoice
                && $subject->revision_id === $revision && $subject->state === $scannerState
                && $subject->proof_sha256 === $record->proof_sha256 && $subject->proof_mime === 'image/png'
                && $job->subject_id === $subject->id && $job->generation === $subject->generation
                && $job->state === ($scannerState === 'QUEUED' ? 'READY' : 'COMPLETED'));
            if ($scannerState === 'CLEAN_ELIGIBLE') {
                $verdict = $db->table('proof_scan_verdicts')->sole();
                self::check($job->attempt_count === 1 && $verdict->attempt_id === $job->current_attempt_id
                    && $verdict->generation === $job->generation && $verdict->verdict === 'clean'
                    && $verdict->observed_sha256 === $subject->proof_sha256 && $verdict->subject_digest === $subject->subject_digest);
            }
            $actions = $db->table('billing_actions')->where('invoice_id', $invoice)->orderBy('invoice_sequence')->pluck('action')->all();
            self::check($actions === ($paid ? ['invoice_issued', 'payment_submitted', 'payment_verified'] : ['invoice_issued', 'payment_submitted']));
            if ($paid) {
                $grants = $db->table('proof_scan_audit_events')->where('subject_id', $subject->id)
                    ->whereIn('event_type', ['protected_get_granted', 'verify_use_granted'])->orderBy('recorded_at')
                    ->get(['event_type', 'principal_ref'])->map(fn ($row) => (array) $row)->all();
                self::check($grants === [['event_type' => 'protected_get_granted', 'principal_ref' => $admin],
                    ['event_type' => 'verify_use_granted', 'principal_ref' => $admin]]);
            }
        }, $guard);
    }

    private static function assertPaymentFact(array $body, string $invoice, string $revision, string $payment, string $fact): void
    {
        self::check(BillingTime::uuid($payment) && ($body['invoice_id'] ?? null) === $invoice
            && ($body['revision_id'] ?? null) === $revision && ($body['payment_id'] ?? null) === $payment
            && ($body['amount'] ?? null) === self::AMOUNT && ($body['command_fact'] ?? null) === $fact
            && ($body['replayed'] ?? null) === false);
    }

    private static function assertFamilyState(BillingMinimumDemoSession $session, string $path, string $invoice, string $status,
        ?string $payment = null, ?string $paymentStatus = null): void
    {
        $body = $session->json('GET', $path, 200);
        $rows = $body['payments'] ?? null;
        self::check(($body['invoice']['id'] ?? null) === $invoice && ($body['invoice']['amount'] ?? null) === self::AMOUNT
            && ($body['invoice']['status'] ?? null) === $status && is_array($rows)
            && ($payment === null ? $rows === [] : count($rows) === 1 && ($rows[0]['id'] ?? null) === $payment
                && ($rows[0]['status'] ?? null) === $paymentStatus));
    }

    private static function field(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        self::check(is_string($value) && $value !== '');

        return $value;
    }

    private static function check(bool $condition): void
    {
        Identity::require($condition, 'billing_demo_assertion_failed');
    }
}
