<?php

// Administrative catalog access is deliberately on postgres, never manpro_db.
if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--http-only')) {
    fwrite(STDERR, "P9-3D menerima mode penuh atau --http-only.\n");
    exit(1);
}
foreach (['APP_ENV' => 'local', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'postgres',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_URL' => '', 'SILANSIA_P93D_VERIFY' => '1',
    'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'false', 'INVOICE_ISSUANCE_ENABLED' => 'false',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-p93d-parent-'.bin2hex(random_bytes(8)).'.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/run-postgres.php';
