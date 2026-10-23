<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Tests;

/** DENNIEOS_CAPTURE_SERVER_ERRORS on: the package attaches its Monolog handler to the default channel. */
abstract class AttachedHandlerTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('dennieos-signals.capture_server_errors', true);
    }
}
