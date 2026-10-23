<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Http\Middleware;

use Closure;
use Dennieos\LaravelSignals\Support\Signature;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC of an intake request over the raw request target (path plus query exactly as
 * sent), in constant time, within a five-minute window, against the current and the previous secret.
 * The refusal never says why: a caller learns nothing about which part was wrong.
 */
final class VerifyIntakeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_values(array_filter([
            config('dennieos-signals.secret'),
            config('dennieos-signals.previous_secret'),
        ], fn (mixed $secret): bool => is_string($secret) && $secret !== ''));

        $target = $request->server->get('REQUEST_URI');
        $target = is_string($target) && $target !== '' ? $target : $request->getRequestUri();

        $result = $secrets === [] ? Signature::INVALID : Signature::verify(
            $secrets,
            $request->headers->get('X-DennieOS-Timestamp'),
            $request->headers->get('X-DennieOS-Signature'),
            $request->getMethod(),
            $target,
            Carbon::now()->getTimestamp(),
        );

        if ($result !== Signature::VALID) {
            return response()->json(['error' => 'unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
