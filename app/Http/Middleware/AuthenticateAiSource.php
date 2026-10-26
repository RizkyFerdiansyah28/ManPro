<?php

namespace App\Http\Middleware;

use App\Exceptions\AiGatewayException;
use App\Support\AiGateway\RequestSignature;
use App\Support\AiGateway\SourceRegistry;
use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateAiSource
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('ai_gateway.enabled') !== true) {
            throw AiGatewayException::unavailable();
        }
        if (! app()->environment('local', 'testing') && ! $request->isSecure()) {
            throw new AiGatewayException(400, 'https_required', 'Gateway memerlukan HTTPS.');
        }
        if ($request->getQueryString() !== null || $request->headers->has('Content-Encoding')
            || strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
            throw new AiGatewayException(415, 'unsupported_request', 'Gunakan JSON tanpa query atau encoding tambahan.');
        }
        $body = $request->getContent();
        if (strlen($body) > config('ai_gateway.max_body_bytes', 8192)) {
            throw new AiGatewayException(413, 'payload_too_large', 'Payload melebihi batas gateway.');
        }
        try {
            $store = config('ai_gateway.cache_store');
            if ($store !== 'database' || config('cache.stores.database.table') !== 'cache'
                || DB::connection()->getDriverName() !== 'pgsql'
                || (config('cache.stores.database.connection') ?: DB::getDefaultConnection()) !== DB::getDefaultConnection()) {
                throw AiGatewayException::unavailable();
            }
            $cache = Cache::store($store);
            if (! $cache->getStore() instanceof DatabaseStore || $cache->getStore()->getConnection() !== DB::connection()) {
                throw AiGatewayException::unavailable();
            }
            $limiter = new RateLimiter($cache);
            $this->limit($limiter, 'ai-gateway:ip:'.hash('sha256', $request->ip() ?? ''), (int) config('ai_gateway.rates.ip'));
        } catch (AiGatewayException $error) {
            throw $error;
        } catch (Throwable) {
            throw AiGatewayException::unavailable();
        }
        $sourceId = $request->header('X-Silansia-Source');
        $keyId = $request->header('X-Silansia-Key');
        $timestamp = $request->header('X-Silansia-Timestamp');
        $nonce = $request->header('X-Silansia-Nonce');
        $authorization = $request->header('Authorization', '');
        $audience = config('ai_gateway.audience');
        if (! is_string($audience) || ! preg_match('/\A[a-zA-Z0-9._:-]{1,100}\z/', $audience)
            || (int) config('ai_gateway.request_window_seconds') !== 300 || (int) config('ai_gateway.nonce_ttl_seconds') < 660) {
            throw AiGatewayException::unavailable();
        }
        if (! SourceRegistry::uuid($sourceId) || ! SourceRegistry::uuid($keyId) || ! is_string($timestamp) || ! preg_match('/\A[1-9][0-9]{9,10}\z/', $timestamp)
            || abs(time() - (int) $timestamp) > 300 || ! is_string($nonce) || ! preg_match('/\A[a-f0-9]{64}\z/', $nonce)
            || ! preg_match('/\ASilansia-HMAC-SHA256 ([a-f0-9]{64})\z/', $authorization, $signature)) {
            $this->unauthorized();
        }
        $source = app(SourceRegistry::class)->all()[$sourceId] ?? null;
        $key = $source['keys'][$keyId] ?? null;
        if (! $source || ! $source['enabled'] || ! $key || ! $key['enabled']
            || ! hash_equals(RequestSignature::sign($audience, $request->getPathInfo(), $sourceId, $keyId, $timestamp, $nonce, $body, $key['secret']), $signature[1])) {
            $this->unauthorized();
        }
        $type = $request->route()->getName() === 'ai.fall-detections' ? 'fall' : 'gait';
        if (! in_array($type, $source['event_types'], true)) {
            throw new AiGatewayException(403, 'source_scope_denied', 'Sumber tidak diizinkan untuk tindakan ini.');
        }
        try {
            if (! $cache->add('ai-gateway:nonce:'.hash('sha256', $sourceId.':'.$nonce), true, 660)) {
                $this->unauthorized();
            }
            $this->limit($limiter, 'ai-gateway:source:'.$sourceId.':'.$type, (int) config('ai_gateway.rates.'.$type));
        } catch (AiGatewayException $error) {
            throw $error;
        } catch (Throwable) {
            throw AiGatewayException::unavailable();
        }
        try {
            $decoded = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiGatewayException(400, 'invalid_json', 'Body JSON tidak valid.');
        }
        if (! $decoded instanceof \stdClass) {
            throw new AiGatewayException(400, 'invalid_json', 'Body harus berupa satu object JSON.');
        }
        // Context tidak menyimpan secret, signature, atau seluruh registry.
        $request->attributes->set('ai_source', ['id' => $sourceId, 'key_id' => $keyId, 'camera_ids' => $source['camera_ids'], 'event_types' => $source['event_types']]);
        $request->attributes->set('ai_payload', (array) $decoded);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function limit(RateLimiter $limiter, string $key, int $maximum): void
    {
        if ($maximum < 1) {
            throw AiGatewayException::unavailable();
        }
        if ($limiter->tooManyAttempts($key, $maximum)) {
            throw new AiGatewayException(429, 'rate_limited', 'Batas request tercapai. Coba kembali nanti.', headers: ['Retry-After' => (string) max(1, $limiter->availableIn($key))]);
        }
        $limiter->hit($key, 60);
    }

    private function unauthorized(): never
    {
        throw new AiGatewayException(401, 'unauthenticated_source', 'Autentikasi sumber tidak sah.');
    }
}
