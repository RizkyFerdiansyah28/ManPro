<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class PaymentCommand
{
    private const FIELDS = ['operation', 'actor_id', 'invoice_id', 'revision_id', 'request_id', 'payment_id',
        'proof_reference', 'expected_state', 'confirm', 'bank_receipt_checked', 'reason_code', 'public_reason', 'private_note'];

    public array $data;

    public function __construct(array $data)
    {
        if (array_diff(array_keys($data), self::FIELDS) || ! in_array($data['operation'] ?? null, ['submit', 'verify', 'reject'], true)
            || ($data['confirm'] ?? null) !== true) {
            throw new PaymentFailure('invalid_command');
        }
        $data += ['payment_id' => null, 'proof_reference' => null, 'expected_state' => null,
            'bank_receipt_checked' => false, 'reason_code' => null, 'public_reason' => null, 'private_note' => null];
        foreach (['actor_id', 'invoice_id', 'revision_id', 'request_id'] as $field) {
            if (! is_string($data[$field] ?? null) || ! BillingTime::uuid($data[$field])) {
                throw new PaymentFailure('invalid_command');
            }
            $data[$field] = strtolower($data[$field]);
        }
        if ($data['operation'] === 'submit') {
            if (! is_string($data['proof_reference']) || ! BillingTime::uuid($data['proof_reference'])
                || $data['payment_id'] !== null || $data['expected_state'] !== null
                || $data['bank_receipt_checked'] !== false || $data['reason_code'] !== null
                || $data['public_reason'] !== null || $data['private_note'] !== null) {
                throw new PaymentFailure('invalid_command');
            }
            $data['proof_reference'] = strtolower($data['proof_reference']);
        } else {
            if (! is_string($data['payment_id']) || ! BillingTime::uuid($data['payment_id']) || $data['proof_reference'] !== null
                || ! is_string($data['expected_state']) || ! preg_match('/\A[0-9a-f]{64}\z/', $data['expected_state'])) {
                throw new PaymentFailure('invalid_command');
            }
            $data['payment_id'] = strtolower($data['payment_id']);
            if ($data['operation'] === 'verify') {
                if ($data['bank_receipt_checked'] !== true || $data['reason_code'] !== null
                    || $data['public_reason'] !== null || $data['private_note'] !== null) {
                    throw new PaymentFailure('bank_confirmation_required');
                }
            } elseif ($data['bank_receipt_checked'] !== false || ! is_string($data['reason_code'])
                || ! preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $data['reason_code'])
                || ! is_string($data['public_reason']) || trim($data['public_reason']) === '' || mb_strlen($data['public_reason']) > 2000
                || ($data['private_note'] !== null && (! is_string($data['private_note']) || mb_strlen($data['private_note']) > 2000))) {
                throw new PaymentFailure('invalid_rejection');
            }
        }
        foreach (['public_reason', 'private_note'] as $field) {
            if (is_string($data[$field]) && (! mb_check_encoding($data[$field], 'UTF-8') || str_contains($data[$field], "\0"))) {
                throw new PaymentFailure('invalid_rejection');
            }
        }
        $this->data = $data;
    }
}
