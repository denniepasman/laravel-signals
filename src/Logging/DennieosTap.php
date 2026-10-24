<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Logging;

use Illuminate\Log\Logger;

/**
 * Optional, for a log channel of your own: `'tap' => [Dennieos\LaravelSignals\Logging\DennieosTap::class]`.
 * The package already attaches its handler to the default channel when DENNIEOS_CAPTURE_SERVER_ERRORS is on.
 */
final class DennieosTap
{
    public function __invoke(Logger $logger): void
    {
        // The handler itself stays silent while the secret is not set, so this needs no check.
        if (config('dennieos-signals.capture_server_errors')) {
            $logger->getLogger()->pushHandler(app(DennieosHandler::class));
        }
    }
}
