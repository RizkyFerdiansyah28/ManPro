<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class CameraSource
{
    public const TYPES = ['webcam', 'uploaded_video', 'rtsp'];

    public static function validate(string $type, #[\SensitiveParameter] mixed $value, bool $required = false): ?string
    {
        if (! in_array($type, self::TYPES, true)) {
            self::reject('Jenis sumber kamera tidak didukung.');
        }
        if ($value === null || $value === '') {
            if ($required && $type !== 'webcam') {
                self::reject('Lengkapi sumber privat sebelum mengaktifkan kamera.');
            }

            return null;
        }
        if (! is_string($value) || strlen($value) > 4096 || $value !== trim($value)
            || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            self::reject('Format sumber privat tidak valid atau melebihi 4096 byte.');
        }
        if ($type === 'webcam') {
            self::reject('Konfigurasi webcam tidak memakai referensi sumber.');
        }
        if ($type === 'rtsp') {
            $parts = parse_url($value);
            $host = is_array($parts) ? trim($parts['host'] ?? '', '[]') : '';
            if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['rtsp', 'rtsps'], true)
                || $host === '' || preg_match('/\s|%(?![a-fA-F0-9]{2})/', $value)
                || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($value)) || isset($parts['fragment'])
                || (! filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
                || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
                self::reject('Gunakan URL rtsp/rtsps yang valid. Sumber tidak diuji koneksinya.');
            }

            return $value;
        }
        // Hanya berkas lokal di root disk privat; tidak membuka URL atau melakukan upload.
        if (str_contains($value, '\\') || str_contains($value, ':') || str_starts_with($value, '/')
            || array_intersect(explode('/', $value), ['', '.', '..']) || config('filesystems.disks.local.driver') !== 'local') {
            self::reject('Gunakan referensi berkas relatif pada storage privat.');
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($value));
        $normalize = fn (string $path) => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : str_replace('\\', '/', $path);
        if (! $root || ! $path || ! is_file($path)
            || ! str_starts_with($normalize($path), rtrim($normalize($root), '/').'/')) {
            self::reject('Berkas privat tidak tersedia atau referensinya tidak aman.');
        }

        return $value;
    }

    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['source_reference' => $message]);
    }
}
