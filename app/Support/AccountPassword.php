<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

final class AccountPassword
{
    public static function rules(): array
    {
        return [...self::inputRules(), 'confirmed', Password::min(12)->mixedCase()->numbers()];
    }

    public static function inputRules(): array
    {
        return ['bail', 'required', 'string', 'max:72',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Kata sandi maksimal 72 byte.');
                }
                if (is_string($value) && str_contains($value, "\0")) {
                    $fail('Kata sandi memuat karakter yang tidak didukung.');
                }
            },
        ];
    }

    public static function messages(): array
    {
        return [
            'password.required' => 'Kata sandi wajib diisi.',
            'password.string' => 'Kata sandi harus berupa teks.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'password.max' => 'Kata sandi maksimal 72 karakter dan 72 byte.',
            'password.min' => 'Kata sandi minimal 12 karakter.',
            'password.password.mixed' => 'Gunakan huruf besar dan kecil pada kata sandi.',
            'password.password.numbers' => 'Sertakan angka pada kata sandi.',
        ];
    }
}
