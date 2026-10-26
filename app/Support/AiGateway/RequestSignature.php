<?php

namespace App\Support\AiGateway;

final class RequestSignature
{
    public static function sign(string $audience, string $path, string $source, string $keyId, string $timestamp, string $nonce, #[\SensitiveParameter] string $body, #[\SensitiveParameter] string $secret): string
    {
        $message = implode("\n", ['SILANSIA-AI-V1', $audience, 'POST', $path, $source, $keyId, $timestamp, $nonce, hash('sha256', $body)]);

        return hash_hmac('sha256', $message, $secret);
    }
}
