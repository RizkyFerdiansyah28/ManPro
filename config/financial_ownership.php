<?php

return [
    // Hanya konfigurasi deployment internal; bukan flag dari request/session.
    'enabled' => env('FINANCIAL_OWNERSHIP_LEDGER_ENABLED', false),
    'cutover_reference' => env('FINANCIAL_OWNERSHIP_CUTOVER_REFERENCE'),
];
