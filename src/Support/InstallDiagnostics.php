<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

use Illuminate\Support\Facades\Route;

/** Detects the cache problems that make a correctly configured install behave as if it were not. */
final class InstallDiagnostics
{
    public const ENV_SAME = 'same';
    public const ENV_DIFFERENT = 'different';
    public const ENV_NOT_SET = 'not_set';
    public const ENV_UNREADABLE = 'unreadable';

    public static function isRouteRegistered(): bool
    {
        return Route::has('dennieos.intake.events');
    }

    public static function isRouteCached(): bool
    {
        return app()->routesAreCached();
    }

    public static function isConfigCached(): bool
    {
        return app()->configurationIsCached();
    }

    /**
     * Compares the secret in the .env file with the one the running config holds, without ever
     * returning either. Only meaningful when the config is cached: otherwise the config is read
     * from the .env on every boot and cannot differ.
     */
    public static function compareEnvSecret(): string
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_readable($path)) {
            return self::ENV_UNREADABLE;
        }
        try {
            $values = \Dotenv\Dotenv::parse((string) file_get_contents($path));
        } catch (\Throwable) {
            return self::ENV_UNREADABLE;
        }
        $fromEnv = $values['DENNIEOS_INTAKE_SECRET'] ?? null;
        if (! is_string($fromEnv) || trim($fromEnv) === '') {
            return self::ENV_NOT_SET;
        }
        $cached = config('dennieos-signals.secret');

        return is_string($cached) && hash_equals($cached, $fromEnv) ? self::ENV_SAME : self::ENV_DIFFERENT;
    }
}
