<?php

if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--payment-ui-only')) {
    fwrite(STDERR, "Runner menerima mode penuh atau --payment-ui-only.\n");
    exit(1);
}
foreach (['APP_ENV' => 'local', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'postgres',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_URL' => '',
    'SILANSIA_P93D_VERIFY' => '1', 'SILANSIA_P94B_VERIFY' => '1', 'SILANSIA_P94D_VERIFY' => '1',
    'FINANCIAL_OWNERSHIP_LEDGER_ENABLED' => 'false', 'INVOICE_ISSUANCE_ENABLED' => 'false', 'PAYMENT_CORE_ENABLED' => 'false',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-p94d-parent-'.bin2hex(random_bytes(8)).'.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/run-postgres.php';
