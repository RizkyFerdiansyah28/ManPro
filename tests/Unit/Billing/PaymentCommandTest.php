<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\PaymentCanonical;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\StoredPaymentProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentCommandTest extends TestCase
{
    private function valid(): array
    {
        return ['operation' => 'submit', 'actor_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'invoice_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'revision_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'request_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'proof_reference' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'confirm' => true];
    }

    public static function invalid(): array
    {
        return [[['amount' => '1.00']], [['proof_path' => 'private/fake.png']], [['proof_sha256' => str_repeat('a', 64)]],
            [['proof_size_bytes' => 1]], [['proof_mime' => 'image/png']], [['proof_disk' => 'local']],
            [['actor_id' => null]], [['invoice_id' => 'bad']], [['revision_id' => 'bad']], [['request_id' => 'bad']],
            [['operation' => 'issue']], [['operation' => 'assist_submit']], [['confirm' => false]], [['confirm' => 'true']],
            [['bank_receipt_checked' => true]], [['expected_state' => 'wrong']], [['proof_reference' => 'not-a-reference']]];
    }

    #[DataProvider('invalid')]
    public function test_invalid_public_input_is_not_a_payment_command(array $changes): void
    {
        $this->expectException(PaymentFailure::class);
        new PaymentCommand(array_replace($this->valid(), $changes));
    }

    public function test_canonical_order_identity_and_namespace_are_stable(): void
    {
        $a = new PaymentCommand($this->valid());
        $b = new PaymentCommand(array_reverse($this->valid(), true));
        $proof = new StoredPaymentProof('fixture/receipt.png', str_repeat('b', 64), 128, 'image/png');
        $c = new PaymentCanonical;
        $this->assertSame($c->intent($a, $proof), $c->intent($b, $proof));
        $this->assertSame($c->intent($a, $proof), $c->intent(new PaymentCommand(array_replace($this->valid(), ['request_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff'])), $proof));
        $this->assertNotSame($c->intent($a, $proof), $c->intent($a, new StoredPaymentProof('fixture/receipt.png', str_repeat('c', 64), 128, 'image/png')));
        $this->assertNotSame(hash('sha256', "silansia.invoice.issue.intent.v1\n[]"), $c->intent($a, $proof));
        $this->assertSame($a->data, (new PaymentCommand(array_map(fn ($v) => is_string($v) && str_contains($v, '-') ? strtoupper($v) : $v, $this->valid())))->data);
    }

    public static function invalidProof(): array
    {
        return [['../escape.png', str_repeat('b', 64), 1, 'image/png'], ['ok.png', 'bad', 1, 'image/png'],
            ['ok.png', str_repeat('b', 64), 0, 'image/png'], ['ok.png', str_repeat('b', 64), 2097153, 'image/png'],
            ['ok.svg', str_repeat('b', 64), 1, 'image/svg+xml'], ['/root/ok.png', str_repeat('b', 64), 1, 'image/png']];
    }

    #[DataProvider('invalidProof')]
    public function test_invalid_metadata_never_becomes_a_valid_descriptor(string $path, string $hash, int $size, string $mime): void
    {
        $this->expectException(PaymentFailure::class);
        new StoredPaymentProof($path, $hash, $size, $mime);
    }

    public function test_default_production_boundary_has_no_issuer_or_raw_metadata_factory(): void
    {
        $this->expectException(PaymentFailure::class);
        $this->expectExceptionMessage('proof_boundary_unavailable');
        (new PaymentProofStore)->resolve($this->valid()['proof_reference'], $this->valid()['actor_id'], $this->valid()['invoice_id']);
    }
}
