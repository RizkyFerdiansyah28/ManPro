<?php

declare(strict_types=1);

namespace App\Services\Billing;

use ErrorException;
use Throwable;

final class PaymentImageValidator
{
    public function validate(string $bytes, string $extension): string
    {
        if (strlen($bytes) < 1 || strlen($bytes) > 2097152
            || ! in_array(strtolower($extension), ['jpg', 'jpeg', 'png'], true)) {
            throw new PaymentFailure('invalid_proof');
        }
        foreach (['finfo_open', 'getimagesizefromstring', 'imagecreatefromstring', 'imagejpeg', 'imagepng'] as $function) {
            if (! function_exists($function)) {
                throw new PaymentFailure('proof_validation_unavailable');
            }
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (($mime !== 'image/png' || strtolower($extension) !== 'png')
            && ($mime !== 'image/jpeg' || ! in_array(strtolower($extension), ['jpg', 'jpeg'], true))) {
            throw new PaymentFailure('invalid_proof');
        }
        // Conservative rejection of known embedded active-content signatures, not an AV claim.
        if (preg_match('/<\?(?:php|=)|<script\b|<html\b/i', $bytes)) {
            throw new PaymentFailure('invalid_proof');
        }
        $mime === 'image/png' ? $this->png($bytes) : $this->jpeg($bytes);
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException('Decoder menolak bukti.', 0, $level);
        });
        $image = null;
        try {
            $info = getimagesizefromstring($bytes);
            if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048
                || $info[0] * $info[1] > 4000000 || ($info['mime'] ?? null) !== $mime) {
                throw new PaymentFailure('invalid_proof');
            }
            $image = imagecreatefromstring($bytes);
            if (! $image || imagesx($image) !== $info[0] || imagesy($image) !== $info[1]) {
                throw new PaymentFailure('invalid_proof');
            }
        } catch (Throwable) {
            throw new PaymentFailure('invalid_proof');
        } finally {
            unset($image);
            restore_error_handler();
        }

        return $mime;
    }

    private function png(string $bytes): void
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new PaymentFailure('invalid_proof');
        }
        $offset = 8;
        $types = [];
        $endedData = false;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($length > strlen($bytes) - $offset - 12) {
                throw new PaymentFailure('invalid_proof');
            }
            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $length);
            if (! in_array($type, ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'pHYs'], true)
                || hash('crc32b', $type.$data) !== bin2hex(substr($bytes, $offset + 8 + $length, 4))
                || ($types === [] && ($type !== 'IHDR' || $length !== 13))
                || ($type === 'IHDR' && $types !== []) || ($type === 'IDAT' && $endedData)) {
                throw new PaymentFailure('invalid_proof');
            }
            if (in_array('IDAT', $types, true) && $type !== 'IDAT') {
                $endedData = true;
            }
            $types[] = $type;
            $offset += $length + 12;
            if ($type === 'IEND') {
                if ($length !== 0 || $offset !== strlen($bytes) || ! in_array('IDAT', $types, true)) {
                    throw new PaymentFailure('invalid_proof');
                }

                return;
            }
        }
        throw new PaymentFailure('invalid_proof');
    }

    private function jpeg(string $bytes): void
    {
        if (! str_starts_with($bytes, "\xff\xd8")) {
            throw new PaymentFailure('invalid_proof');
        }
        $offset = 2;
        $frame = false;
        $scan = false;
        while ($offset < strlen($bytes)) {
            if (ord($bytes[$offset++]) !== 255) {
                throw new PaymentFailure('invalid_proof');
            }
            while ($offset < strlen($bytes) && ord($bytes[$offset]) === 255) {
                $offset++;
            }
            if ($offset >= strlen($bytes)) {
                break;
            }
            $marker = ord($bytes[$offset++]);
            if ($marker === 217) {
                if ($frame && $scan && $offset === strlen($bytes)) {
                    return;
                }
                break;
            }
            if (! in_array($marker, [192, 194, 196, 219, 221, 218, 254, ...range(224, 239)], true)
                || $offset + 2 > strlen($bytes)) {
                break;
            }
            $length = unpack('n', substr($bytes, $offset, 2))[1];
            if ($length < 2 || $offset + $length > strlen($bytes)) {
                break;
            }
            $frame = $frame || in_array($marker, [192, 194], true);
            $offset += $length;
            if ($marker === 218) {
                $scan = true;
                while ($offset < strlen($bytes)) {
                    if (ord($bytes[$offset]) !== 255) {
                        $offset++;

                        continue;
                    }
                    $next = $offset + 1;
                    while ($next < strlen($bytes) && ord($bytes[$next]) === 255) {
                        $next++;
                    }
                    if ($next >= strlen($bytes)) {
                        break;
                    }
                    if (ord($bytes[$next]) === 0 || in_array(ord($bytes[$next]), range(208, 215), true)) {
                        $offset = $next + 1;

                        continue;
                    }
                    break;
                }
            }
        }
        throw new PaymentFailure('invalid_proof');
    }
}
