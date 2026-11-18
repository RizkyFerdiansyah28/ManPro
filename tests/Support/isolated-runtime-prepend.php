<?php

use Tests\Support\FrameworkRuntimeIsolation;

require_once __DIR__.'/FrameworkRuntimeIsolation.php';
try {
    FrameworkRuntimeIsolation::environment();
} catch (Throwable $error) {
    $reason = $error instanceof LogicException && str_starts_with($error->getMessage(), 'runtime_') ? $error->getMessage() : 'runtime_preflight_failure';
    echo json_encode(['ok' => false, 'reason' => $reason]);
    exit(78);
}
