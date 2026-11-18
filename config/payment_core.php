<?php

return [
    // P9-4B is internal and owned-testing-only. This never authorizes operational activation.
    'enabled' => env('PAYMENT_CORE_ENABLED', false),
];
