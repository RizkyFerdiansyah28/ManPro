<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AvailableAccountEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->exists()) {
            $fail('Alamat email sudah digunakan.');
        }
    }
}
