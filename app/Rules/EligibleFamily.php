<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EligibleFamily implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! User::query()->whereKey($value)->where('role', 'keluarga')->where('is_active', true)->exists()) {
            $fail('Pilih akun keluarga yang aktif.');
        }
    }
}
