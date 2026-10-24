<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals;

/** The package is inert until the owner sets DENNIEOS_INTAKE_SECRET (config intake secret). */
final class Activation
{
    public const INACTIVE_MESSAGE = 'inactive: DENNIEOS_INTAKE_SECRET is not set';

    public static function isActive(): bool
    {
        $secret = config('dennieos-signals.secret');

        return is_string($secret) && trim($secret) !== '';
    }
}
