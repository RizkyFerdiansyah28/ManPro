<?php

namespace App\Http\Requests\Billing;

use App\Exceptions\InvoiceHttpException;
use App\Models\Invoice;
use App\Services\Billing\BillingTime;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvoiceHttpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match (true) {
            $this->routeIs('*.preview', '*.create') => 'preview',
            $this->routeIs('*.store') => 'issue', $this->routeIs('*.reconcile') => 'reconcile', default => 'viewAny',
        };

        return Gate::allows($ability, Invoice::class);
    }

    public function rules(): array
    {
        $month = ['bail', 'string', function ($attribute, $value, $fail) {
            try {
                BillingTime::month($value);
            } catch (\Throwable) {
                $fail('Bulan harus valid dengan format YYYY-MM-01.');
            }
        }];
        if ($this->isMethod('POST')) {
            $rules = ['elderly_id' => ['required', 'string', 'uuid'], 'billing_month' => ['required', ...$month]];
            if (! $this->routeIs('*.preview')) {
                $rules += ['request_id' => ['required', 'string', 'uuid'], 'confirmation_token' => ['required', 'string', 'max:4096']];
                if ($this->routeIs('*.store')) {
                    $rules['confirm'] = ['required', 'accepted', fn ($a, $v, $fail) => $v === true ?: $fail('Konfirmasikan penerbitan invoice.')];
                }
            }

            return $rules;
        }
        $page = ['nullable', 'integer', 'between:1,10000'];
        if ($this->routeIs('*.index')) {
            return ['elderly_id' => ['nullable', 'string', 'uuid'], 'billing_month' => ['nullable', ...$month],
                'status' => ['nullable', Rule::in(['unpaid', 'paid', 'cancelled'])], 'page' => $page,
                'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])],
                'sort' => ['nullable', Rule::in(['billing_month_desc', 'billing_month_asc', 'due_date_asc'])]];
        }
        if ($this->routeIs('*.resident-options')) {
            return ['search' => ['nullable', 'string', 'max:100'], 'page' => $page,
                'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])]];
        }
        if ($this->routeIs('*.create')) {
            return ['elderly_id' => ['nullable', 'string', 'uuid'], 'billing_month' => ['nullable', ...$month]];
        }

        return [];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('input', 'Parameter tidak diizinkan.');
            }
        });
    }

    protected function failedValidation(Validator $validator): never
    {
        $safe = [];
        foreach (array_keys($validator->errors()->toArray()) as $field) {
            $safe[$field] = 'Periksa isian permintaan invoice.';
        }
        throw new InvoiceHttpException('invalid_input', $safe);
    }
}
