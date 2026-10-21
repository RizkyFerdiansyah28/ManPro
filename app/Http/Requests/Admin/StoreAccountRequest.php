<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\AvailableAccountEmail;
use App\Support\AccountPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $field === 'email' ? Str::lower(trim($this->input($field))) : trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254', new AvailableAccountEmail],
            'password' => AccountPassword::rules(),
            'role' => ['missing'], 'is_active' => ['missing'], 'id' => ['missing'],
            'remember_token' => ['missing'], 'email_verified_at' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [...AccountPassword::messages(), 'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.', 'email.email' => 'Format email tidak valid.',
            'max' => ':attribute terlalu panjang.', 'missing' => 'Kolom :attribute tidak diizinkan.'];
    }

    public function attributes(): array
    {
        return ['name' => 'Nama', 'email' => 'Email'];
    }
}
