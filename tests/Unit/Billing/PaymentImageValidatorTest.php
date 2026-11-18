<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentImageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentImageValidatorTest extends TestCase
{
    public static function encoded(string $type, int $width = 2, int $height = 2): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);
        $bytes = ob_get_clean();
        unset($image);

        return $bytes;
    }

    public function test_valid_original_jpeg_and_png_and_exact_two_mib_boundary(): void
    {
        $validator = new PaymentImageValidator;
        $jpeg = self::encoded('jpg');
        $this->assertSame('image/jpeg', $validator->validate($jpeg, 'jpeg'));
        $this->assertSame('image/png', $validator->validate(self::encoded('png'), 'png'));
        $padding = '';
        $remaining = 2097152 - strlen($jpeg);
        while ($remaining > 0) {
            $length = min(65537, $remaining);
            if ($remaining - $length > 0 && $remaining - $length < 4) {
                $length -= 4;
            }
            $padding .= "\xff\xef".pack('n', $length - 2).str_repeat('x', $length - 4);
            $remaining -= $length;
        }
        $boundary = substr($jpeg, 0, 2).$padding.substr($jpeg, 2);
        $this->assertSame(2097152, strlen($boundary));
        $this->assertSame('image/jpeg', $validator->validate($boundary, 'jpg'));
        $this->expectException(PaymentFailure::class);
        $validator->validate($boundary.'x', 'jpg');
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($case) => [$case], ['empty', 'extension', 'mime', 'corrupt', 'truncated_png', 'truncated_jpeg', 'trailing_png', 'trailing_jpeg', 'crc', 'width', 'height', 'pixels', 'active_content', 'oversize']);
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_content_fails_for_the_intended_profile(string $case): void
    {
        $bytes = self::encoded('png');
        $extension = 'png';
        switch ($case) {
            case 'empty': $bytes = '';
                break;
            case 'extension': $extension = 'php';
                break;
            case 'mime': $extension = 'jpg';
                break;
            case 'corrupt': $bytes = 'not an image';
                break;
            case 'truncated_png': $bytes = substr($bytes, 0, -8);
                break;
            case 'truncated_jpeg': $bytes = substr(self::encoded('jpg'), 0, -2);
                $extension = 'jpg';
                break;
            case 'trailing_png': $bytes .= 'trailing';
                break;
            case 'trailing_jpeg': $bytes = self::encoded('jpg').'trailing';
                $extension = 'jpg';
                break;
            case 'crc': $bytes[30] = chr(ord($bytes[30]) ^ 1);
                break;
            case 'width': $bytes = self::encoded('png', 2049, 1);
                break;
            case 'height': $bytes = self::encoded('png', 1, 2049);
                break;
            case 'pixels': $bytes = self::encoded('png', 2048, 2048);
                break;
            case 'active_content': $bytes = self::encoded('jpg');
                $payload = '<?php echo 1;';
                $bytes = substr($bytes, 0, 2)."\xff\xfe".pack('n', strlen($payload) + 2).$payload.substr($bytes, 2);
                $extension = 'jpg';
                break;
            case 'oversize': $bytes = str_repeat('x', 2097153);
                break;
        }
        try {
            (new PaymentImageValidator)->validate($bytes, $extension);
            $this->fail('Bukti harus ditolak.');
        } catch (PaymentFailure $error) {
            $this->assertSame('invalid_proof', $error->kind);
        }
    }
}
