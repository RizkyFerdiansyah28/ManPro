<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class MonthlyPrice implements ValidationRule
{
    private const MESSAGE = 'Tarif harus berupa angka nonnegatif, maksimal 12 digit sebelum titik dan 2 digit desimal. Kosongkan jika belum diatur.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            self::normalize($value);
        } catch (ValidationException) {
            $fail(self::MESSAGE);
        }
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = match (true) {
            is_string($value), is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
            default => false,
        };
        // Tidak memakai float untuk pembandingan/range/rounding nominal.
        if (! is_string($text) || ! preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/', $text)) {
            throw ValidationException::withMessages(['monthly_price' => self::MESSAGE]);
        }

        return $text;
    }
}
