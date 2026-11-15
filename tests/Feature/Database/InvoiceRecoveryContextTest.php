<?php

namespace Tests\Feature\Database;

use App\Exceptions\InvoiceHttpException;
use App\Models\User;
use App\Support\Billing\InvoiceRecoveryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceHttpTestCase;

#[Group('invoice-http')]
class InvoiceRecoveryContextTest extends InvoiceHttpTestCase
{
    public function test_context_is_current_readonly_stable_and_not_a_shared_prop_or_token(): void
    {
        $s = $this->source();
        $this->loginFixture($s['admin']);
        $this->withSession(['context_test' => 'preserved']);
        $before = $this->fingerprints();
        $states = [];
        DB::listen(function ($query) use (&$states) {
            if (str_contains($query->sql, '/* invoice-recovery-context */')) {
                $states[] = DB::scalar('SHOW transaction_read_only');
            }
        });
        $a = $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk();
        $context = $a->json('props.recovery_context');
        $this->assertSame(['version', 'actor_id', 'session_context', 'audience_context'], array_keys($context));
        $this->assertSame($s['admin']->id, $context['actor_id']);
        $identity = DB::selectOne('SELECT current_database() AS name,oid::text FROM pg_database WHERE datname=current_database()');
        $this->assertSame(InvoiceRecoveryContext::fingerprints(session()->getId(), $s['admin']->id, 'testing',
            $identity->name, $identity->oid, app('encrypter')->getKey()), $context);
        Auth::forgetGuards();
        $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk()->assertJsonPath('props.recovery_context', $context);
        $this->assertSame(['on', 'on'], $states);
        foreach ([session()->getId(), session()->token(), $identity->name, config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($context));
        }
        $this->assertNoPrivateError($a);
        $this->assertStringNotContainsString('confirmation_token', $a->getContent());
        $this->assertSame('preserved', session('context_test'));
        $this->assertFalse(session()->has('recovery_context'));
        $this->get(self::HTTP_BASE.'?elderly_id='.$s['elderly']->id, $this->inertiaHeaders())->assertOk()->assertJsonMissingPath('props.recovery_context');
        $this->get('/admin', $this->inertiaHeaders())->assertOk()->assertJsonMissingPath('props.recovery_context');
        $this->preserve($before, []);
    }

    public function test_actual_logout_and_same_actor_login_change_session_context_without_changing_audience(): void
    {
        $s = $this->source();
        $this->loginFixture($s['admin']);
        $a = $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk()->json('props.recovery_context');
        $this->post('/logout')->assertRedirect('/login');
        $this->carrySessionCookie();
        $this->getJson(self::HTTP_BASE.'/create')->assertUnauthorized()->assertJsonMissingPath('recovery_context');
        $this->loginFixture($s['admin']);
        $b = $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk()->json('props.recovery_context');
        $this->assertSame($a['actor_id'], $b['actor_id']);
        $this->assertNotSame($a['session_context'], $b['session_context']);
        $this->assertSame($a['audience_context'], $b['audience_context']);
        // An old Inertia page has old metadata; only a fresh server GET establishes the new context.
        $this->assertNotSame($a, $b);
    }

    public function test_actual_account_switch_changes_actor_and_session_binding(): void
    {
        $s = $this->source();
        $next = $this->account();
        $this->loginFixture($s['admin']);
        $a = $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk()->json('props.recovery_context');
        $firstSession = session()->getId();
        $this->post('/logout')->assertRedirect('/login');
        $this->carrySessionCookie();
        $this->loginFixture($next);
        $b = $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk()->json('props.recovery_context');
        $this->assertNotSame($firstSession, session()->getId());
        $this->assertNotSame($a['actor_id'], $b['actor_id']);
        $this->assertNotSame($a['session_context'], $b['session_context']);
        $this->assertSame($a['audience_context'], $b['audience_context']);
    }

    public function test_gate_off_and_invalid_owned_identity_do_not_expose_context_or_query_metadata(): void
    {
        $s = $this->source();
        $this->actingAs($s['admin']);
        $before = $this->fingerprints();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, '/* invoice-recovery-context */')) {
                $queries[] = $query->sql;
            }
        });
        config(['invoice_issuance.enabled' => false]);
        $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertStatus(503)->assertJsonMissingPath('props.recovery_context');
        config(['invoice_issuance.enabled' => true]);
        $identity = getenv('SILANSIA_TEST_IDENTITY');
        try {
            putenv('SILANSIA_TEST_IDENTITY='.json_encode([...json_decode($identity, true), 'oid' => '0']));
            $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertStatus(503)->assertJsonMissingPath('props.recovery_context');
        } finally {
            putenv('SILANSIA_TEST_IDENTITY='.$identity);
        }
        $this->assertSame([], $queries);
        $this->preserve($before, []);
    }

    public function test_context_marker_cannot_authorize_commands_or_replace_confirmation(): void
    {
        $s = $this->source();
        $command = $this->httpCommand($s);
        $context = $this->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertOk()->json('props.recovery_context');
        $before = $this->fingerprints();
        $this->postJson(self::HTTP_BASE, [...$command, 'confirmation_token' => $context['session_context']])->assertUnprocessable();
        $this->postJson(self::HTTP_BASE, [...$command, 'recovery_context' => $context])->assertUnprocessable();
        $this->flushSession();
        Auth::forgetGuards();
        $this->actingAs($s['family'])->get(self::HTTP_BASE.'/create', $this->inertiaHeaders())->assertForbidden()->assertJsonMissingPath('props.recovery_context');
        $this->postJson(self::HTTP_BASE, $command)->assertForbidden()->assertJsonMissingPath('invoice_id');
        $this->preserve($before, []);
    }

    public function test_direct_helper_refuses_missing_session_and_rechecks_database_actor(): void
    {
        $s = $this->source();
        $helper = app(InvoiceRecoveryContext::class);
        $request = Request::create(self::HTTP_BASE.'/create');
        $request->setUserResolver(fn () => $s['admin']);
        try {
            $helper->forRequest($request);
            $this->fail('A session must be present.');
        } catch (InvoiceHttpException $error) {
            $this->assertSame('unauthorized', $error->kind);
        }
        $request->setLaravelSession(session()->driver());
        session()->start();
        DB::table('users')->where('id', $s['admin']->id)->update(['role' => 'perawat']);
        try {
            $helper->forRequest($request); // Cached User object still says admin; SELECT must reject.
            $this->fail('A stale actor object must not authorize context.');
        } catch (InvoiceHttpException $error) {
            $this->assertSame('unauthorized', $error->kind);
        }
    }

    private function carrySessionCookie(): void
    {
        // The framework encrypts this cookie for the next request, as a browser would return it.
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
    }

    private function loginFixture(User $actor): void
    {
        Auth::forgetGuards();
        $this->post('/login', ['email' => $actor->email, 'password' => self::PASSWORD])->assertRedirect('/admin');
        $this->carrySessionCookie();
        Auth::forgetGuards();
    }
}
