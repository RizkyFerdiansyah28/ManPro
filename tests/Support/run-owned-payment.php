<?php

use Tests\Support\FrameworkRuntimeIsolation;

// Mandatory entry point: even total loss of the isolation env/ini cannot fall back to the legacy runner.
require_once __DIR__.'/FrameworkRuntimeIsolation.php';
try {
    FrameworkRuntimeIsolation::environment();
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'reason' => $error instanceof LogicException ? $error->getMessage() : 'runtime_preflight_failure']);
    exit(78);
}
require __DIR__.'/run-payment-security.php';
