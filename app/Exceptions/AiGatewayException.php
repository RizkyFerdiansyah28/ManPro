<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class AiGatewayException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $errors = [], public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        return true; // Penolakan terkontrol: tidak mencatat payload/header privat.
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $this->errorCode, 'message' => $this->getMessage()],
            'request_id' => (string) Str::uuid(),
            ...($this->errors === [] ? [] : ['errors' => $this->errors]),
        ], $this->status, $this->headers)->header('Cache-Control', 'no-store, private');
    }

    public static function unavailable(): self
    {
        return new self(503, 'gateway_unavailable', 'Gateway belum tersedia. Coba kembali nanti.');
    }
}
