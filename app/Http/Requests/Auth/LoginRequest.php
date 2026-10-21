<?php

namespace App\Http\Requests\Auth;

use App\Support\AccountPassword;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => AccountPassword::inputRules(),
            'remember' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'email.email' => 'Format email tidak valid.',
            'string' => ':attribute harus berupa teks.', 'max' => ':attribute terlalu panjang.',
            'missing' => 'Kolom :attribute tidak diizinkan.'];
    }

    public function authenticate(): void
    {
        $key = hash('sha256', $this->string('email')->toString()).'|'.$this->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan masuk. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        if (! Auth::guard('web')->attempt([...$this->safe()->only(['email', 'password']), 'is_active' => true], false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai, atau akun belum aktif.']);
        }
        RateLimiter::clear($key);
    }
}
