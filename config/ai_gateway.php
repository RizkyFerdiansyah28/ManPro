<?php

return [
    'enabled' => (bool) env('AI_GATEWAY_ENABLED', false),
    'audience' => env('AI_GATEWAY_AUDIENCE', ''),
    // JSON privat: registry sumber/key/allowlist. Tidak dibagikan ke frontend.
    'sources_json' => env('AI_GATEWAY_SOURCES_JSON', '[]'),
    'cache_store' => 'database',
    'request_window_seconds' => 300,
    'nonce_ttl_seconds' => 660,
    'max_body_bytes' => 8192,
    'max_event_age_seconds' => 86400,
    'lock_timeout_ms' => 5000,
    'rates' => ['ip' => 120, 'fall' => 120, 'gait' => 600],
];
