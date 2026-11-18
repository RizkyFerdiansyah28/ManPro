<?php

// P9-4E reuses the accepted owned PostgreSQL runners and all their guards unchanged.
if (count($argv) > 2 || (isset($argv[1]) && ! in_array($argv[1], ['--payment-http-only', '--payment-ui-only'], true))) {
    fwrite(STDERR, "Gunakan mode penuh, --payment-http-only, atau --payment-ui-only.\n");
    exit(1);
}
putenv('SILANSIA_P94E_VERIFY=1');
$_ENV['SILANSIA_P94E_VERIFY'] = $_SERVER['SILANSIA_P94E_VERIFY'] = '1';
require __DIR__.(($argv[1] ?? '') === '--payment-http-only' ? '/run-payment-http.php' : '/run-payment-ui.php');
