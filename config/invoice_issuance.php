<?php

return [
    // P9-2C hanya mengizinkan owned test DB. Gate ini tidak mengizinkan aktivasi operasional.
    'enabled' => env('INVOICE_ISSUANCE_ENABLED', false),
];
