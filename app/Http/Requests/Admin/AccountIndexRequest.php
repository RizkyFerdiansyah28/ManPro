<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', User::class);
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['admin', ...User::MANAGED_ROLES])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])], 'page' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['in' => 'Pilihan :attribute tidak valid.', 'string' => ':attribute harus berupa teks.',
            'max' => ':attribute terlalu panjang.', 'integer' => ':attribute harus berupa angka.', 'min' => ':attribute tidak valid.'];
    }
}
