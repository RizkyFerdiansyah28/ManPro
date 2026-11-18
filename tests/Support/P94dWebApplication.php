<?php

namespace Tests\Support;

use App\Services\Billing\PaymentProofEnvironment;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

final class P94dWebApplication
{
    public static function boot(): Application
    {
        if (getenv('SILANSIA_P94D_VERIFY') !== '1') {
            throw new \LogicException('p94d_environment_rejected');
        }
        $app = P93dWebApplication::boot();
        PostgresTestGuard::fromEnvironment()->assertSafe($app, DB::connection());
        config(['payment_core.enabled' => true, 'payment_http.enabled' => true, 'payment_http.synthetic_validation' => true]);
        app(PaymentProofEnvironment::class)->root();

        return $app;
    }
}
