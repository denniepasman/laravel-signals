<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Logging;

use Dennieos\LaravelSignals\Support\BrowserFamily;
use Dennieos\LaravelSignals\Support\UrlPath;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** What the handler knows about the failing request or console command; never throws. */
final class RequestContext
{
    /**
     * @return array{context: array<string, mixed>, user_id: string|int|null}
     */
    public static function capture(?\Throwable $exception): array
    {
        try {
            return app()->runningInConsole() ? self::console() : self::http($exception);
        } catch (\Throwable) {
            return ['context' => [], 'user_id' => null];
        }
    }

    /** @return array{context: array<string, mixed>, user_id: string|int|null} */
    private static function console(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $isArtisan = isset($argv[0]) && basename((string) $argv[0]) === 'artisan';
        $command = null;
        foreach (array_slice($argv, 1) as $argument) {
            if (is_string($argument) && ! str_starts_with($argument, '-')) {
                $command = $argument;
                break;
            }
        }

        return ['context' => $isArtisan && $command !== null ? ['command' => $command] : [], 'user_id' => null];
    }

    /** @return array{context: array<string, mixed>, user_id: string|int|null} */
    private static function http(?\Throwable $exception): array
    {
        $request = request();
        $route = $request->route();
        $pattern = is_object($route) && method_exists($route, 'uri') ? '/'.ltrim((string) $route->uri(), '/') : null;
        $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;

        return [
            'context' => [
                'route' => $routeName ?? $pattern,
                'method' => $request->getMethod(),
                'status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500,
                'browser' => BrowserFamily::parse($request->userAgent()),
                // The route pattern keeps `{param}` as `:param`; without a route the path is normalised.
                'url_path' => $pattern !== null
                    ? (string) preg_replace('~\{(\w+)\??\}~', ':$1', $pattern)
                    : UrlPath::normalise('/'.ltrim($request->path(), '/')),
            ],
            'user_id' => self::userId(),
        ];
    }

    private static function userId(): string|int|null
    {
        try {
            $guard = Auth::guard();
            // hasUser() only looks at an already resolved user: no query while the app is failing.
            if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                $id = $guard->user()?->getAuthIdentifier();

                return is_string($id) || is_int($id) ? $id : null;
            }
        } catch (\Throwable) {
            // No guard configured or the session is unavailable: no user is recorded.
        }

        return null;
    }
}
