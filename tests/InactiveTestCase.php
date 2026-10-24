<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Tests;

/** DENNIEOS_INTAKE_SECRET not set, everything else as an installation would have it by default. */
abstract class InactiveTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('dennieos-signals.secret', null);
        $app['config']->set('dennieos-signals.previous_secret', null);
        $app['config']->set('dennieos-signals.capture_server_errors', true);
        $app['config']->set('dennieos-signals.schedule', true);
        $app['config']->set('dennieos-signals.route.enabled', true);
    }
}
