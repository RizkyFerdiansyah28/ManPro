<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoiceRecoveryContext;
use PHPUnit\Framework\TestCase;

class InvoiceRecoveryContextTest extends TestCase
{
    public function test_markers_are_stable_domain_separated_and_not_raw_secrets(): void
    {
        $facts = ['synthetic-session', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'testing', 'synthetic-owned-db', '123', 'synthetic-key'];
        $context = InvoiceRecoveryContext::fingerprints(...$facts);
        $this->assertSame($context, InvoiceRecoveryContext::fingerprints(...$facts));
        $this->assertSame(['version', 'actor_id', 'session_context', 'audience_context'], array_keys($context));
        $this->assertSame(1, $context['version']);
        foreach (['session_context', 'audience_context'] as $field) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $context[$field]);
        }
        $this->assertNotSame($context['session_context'], $context['audience_context']);
        foreach ([0, 2, 3, 5] as $index) {
            $this->assertStringNotContainsString($facts[$index], json_encode($context));
        }
        foreach (range(0, 5) as $index) {
            $changed = $facts;
            $changed[$index] .= '-changed';
            $next = InvoiceRecoveryContext::fingerprints(...$changed);
            $this->assertNotSame($context, $next);
            $this->assertSame(in_array($index, [0, 1, 5], true), $context['session_context'] !== $next['session_context']);
            $this->assertSame(in_array($index, [2, 3, 4, 5], true), $context['audience_context'] !== $next['audience_context']);
        }
    }
}
