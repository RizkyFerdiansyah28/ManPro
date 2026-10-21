<?php

namespace App\Http\Requests\Admin;

use App\Support\AccountPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ResetAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('resetPassword', $this->route('user'));
    }

    public function rules(): array
    {
        return ['current_password' => [...AccountPassword::inputRules(), 'current_password:web'],
            'password' => AccountPassword::rules(), 'role' => ['missing'], 'is_active' => ['missing'], 'id' => ['missing']];
    }

    public function messages(): array
    {
        return [...AccountPassword::messages(), 'current_password.required' => 'Masukkan kata sandi Admin Anda.',
            'current_password.current_password' => 'Kata sandi Admin tidak sesuai.',
            'current_password.string' => 'Kata sandi Admin harus berupa teks.',
            'current_password.max' => 'Kata sandi Admin terlalu panjang.', 'missing' => 'Kolom :attribute tidak diizinkan.'];
    }
}
