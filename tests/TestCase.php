<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Tests\Support\FrameworkRuntimeIsolation;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        FrameworkRuntimeIsolation::environment();
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        if (in_array(WithCachedConfig::class, $this->traitsUsedByTest, true)
            || in_array(WithCachedRoutes::class, $this->traitsUsedByTest, true)) {
            throw new \LogicException('runtime_cached_application_not_supported');
        }
        FrameworkRuntimeIsolation::attach($app);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
