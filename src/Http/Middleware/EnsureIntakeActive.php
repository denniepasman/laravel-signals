<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Http\Middleware;

use Closure;
use Dennieos\LaravelSignals\Activation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route is always registered (so a cached route file never lacks it), and whether the package
 * is active is decided here, per request. Without a secret the answer is an empty 404, the same
 * thing a missing route gave before, and nothing touches the database.
 */
final class EnsureIntakeActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Activation::isActive()) {
            return response('', 404);
        }

        return $next($request);
    }
}
