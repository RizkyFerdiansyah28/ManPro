<?php

namespace App\Support\AiGateway;

use App\Exceptions\AiGatewayException;
use Illuminate\Support\Str;
use JsonException;

final class SourceRegistry
{
    public function all(): array
    {
        try {
            $sources = json_decode(config('ai_gateway.sources_json'), true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException|\TypeError) {
            throw AiGatewayException::unavailable();
        }
        if (! is_array($sources) || ! array_is_list($sources)) {
            throw AiGatewayException::unavailable();
        }
        $result = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! self::uuid($source['id'] ?? null) || isset($result[$source['id']])
                || ! is_bool($source['enabled'] ?? null) || ! is_array($source['camera_ids'] ?? null) || ! array_is_list($source['camera_ids'])
                || ! is_array($source['event_types'] ?? null) || ! array_is_list($source['event_types']) || ! is_array($source['keys'] ?? null) || ! array_is_list($source['keys'])) {
                throw AiGatewayException::unavailable();
            }
            foreach ($source['camera_ids'] as $id) {
                if (! self::uuid($id)) {
                    throw AiGatewayException::unavailable();
                }
            }
            foreach ($source['event_types'] as $type) {
                if (! in_array($type, ['fall', 'gait'], true)) {
                    throw AiGatewayException::unavailable();
                }
            }
            $keys = [];
            foreach ($source['keys'] as $key) {
                if (! is_array($key) || ! self::uuid($key['id'] ?? null) || isset($keys[$key['id']])
                    || ! is_bool($key['enabled'] ?? null) || ! is_string($key['secret'] ?? null)
                    || strlen($key['secret']) < 32 || strlen($key['secret']) > 256 || preg_match('/[\x00-\x20\x7F]/', $key['secret'])) {
                    throw AiGatewayException::unavailable();
                }
                $keys[$key['id']] = $key;
            }
            $result[$source['id']] = [...$source, 'keys' => $keys];
        }

        return $result;
    }

    public static function uuid(mixed $value): bool
    {
        return is_string($value) && strtolower($value) === $value && Str::isUuid($value);
    }
}
