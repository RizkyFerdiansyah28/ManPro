<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PaymentHttpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'payment.family.index' => ['page' => 'sometimes|integer|min:1|max:10000', 'status' => 'sometimes|in:unpaid,paid,cancelled'],
            'payment.admin.index' => ['page' => 'sometimes|integer|min:1|max:10000', 'status' => 'sometimes|in:pending,verified,rejected'],
            'payment.admin.context' => ['operation' => 'required|in:verify,reject'],
            'payment.family.submit' => ['command_context' => 'required|string|max:12000', 'confirm' => 'required|accepted', 'proof' => 'required|file|max:2048'],
            'payment.admin.verify' => ['command_context' => 'required|string|max:12000', 'confirm' => 'required|boolean|accepted', 'bank_receipt_checked' => 'required|boolean|accepted'],
            'payment.admin.reject' => ['command_context' => 'required|string|max:12000', 'confirm' => 'required|boolean|accepted', 'reason_code' => ['required', 'regex:/\A[a-z][a-z0-9_]{0,39}\z/'], 'public_reason' => 'required|string|max:2000', 'private_note' => 'nullable|string|max:2000'],
            'payment.reconcile' => ['command_context' => 'required|string|max:12000', 'recovery_context' => 'required|string|max:3000'],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $keys = array_keys($this->all());
            if (array_diff($keys, array_keys($this->rules()))) {
                $validator->errors()->add('request', 'Isian permintaan tidak diizinkan.');
            }
            if ($this->routeIs('payment.admin.verify', 'payment.admin.reject')
                && ($this->input('confirm') !== true || ($this->routeIs('payment.admin.verify') && $this->input('bank_receipt_checked') !== true))) {
                $validator->errors()->add('request', 'Konfirmasi eksplisit diperlukan.');
            }
        }];
    }
}
