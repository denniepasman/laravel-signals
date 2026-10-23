<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

final class UrlPath
{
    /** A path with ids replaced by `:id` and no query string or host (contract: `context.url_path`). */
    public static function normalise(string $urlOrPath): ?string
    {
        $path = parse_url($urlOrPath, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }
        $segments = array_map(
            fn (string $segment): string => self::isIdentifier($segment) ? ':id' : $segment,
            explode('/', $path),
        );

        return substr(implode('/', $segments), 0, 255);
    }

    private static function isIdentifier(string $segment): bool
    {
        return preg_match('~^\d+$~', $segment) === 1
            || preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $segment) === 1
            || preg_match('~^[0-9a-f]{16,}$~i', $segment) === 1
            || preg_match('~^[0-9A-HJKMNP-TV-Z]{26}$~', $segment) === 1;
    }
}
