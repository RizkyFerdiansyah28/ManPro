<?php

namespace App\Support;

use App\Models\Camera;
use Illuminate\Validation\ValidationException;

final class CameraVersion
{
    public static function of(Camera $camera): string
    {
        $attributes = [];
        foreach (['id', 'room_id', 'name', 'source_type', 'source_reference', 'is_active', 'created_at', 'updated_at'] as $field) {
            $attributes[$field] = $camera->getRawOriginal($field);
        }

        // Token konflik edit, bukan token akses sumber. Ciphertext/ref tidak dikirim ke frontend.
        return hash_hmac('sha256', json_encode($attributes, JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    }

    public static function ensureMatches(Camera $camera, string $version): void
    {
        if (! hash_equals(self::of($camera), $version)) {
            throw ValidationException::withMessages(['version' => 'Konfigurasi kamera sudah berubah. Muat ulang halaman sebelum melanjutkan.']);
        }
    }
}
