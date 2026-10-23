<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

/**
 * Signing of the client intake contract v1 (docs/integrations/client-intake-contract.md → Signing).
 *
 * string_to_sign = timestamp + "\n" + METHOD + "\n" + path_and_query
 * signature      = "sha256=" + lowercase hex HMAC-SHA256(secret, string_to_sign)
 */
final class Signature
{
    public const MAX_CLOCK_SKEW_SECONDS = 300;

    public const VALID = 'valid';
    public const MALFORMED = 'malformed';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    public static function sign(string $secret, int $timestamp, string $method, string $pathAndQuery): string
    {
        return 'sha256='.self::digest($secret, (string) $timestamp, $method, $pathAndQuery);
    }

    /**
     * @param  list<string>  $secrets  the current secret first; during a rotation also the previous one
     * @return self::VALID|self::MALFORMED|self::EXPIRED|self::INVALID
     */
    public static function verify(
        array $secrets,
        ?string $timestampHeader,
        ?string $signatureHeader,
        string $method,
        string $pathAndQuery,
        int $now,
    ): string {
        if ($timestampHeader === null || preg_match('~^[0-9]{1,15}$~', $timestampHeader) !== 1) {
            return self::MALFORMED;
        }
        if ($signatureHeader === null || preg_match('~^sha256=([0-9a-f]{64})$~', $signatureHeader, $match) !== 1) {
            return self::MALFORMED;
        }
        if (abs($now - (int) $timestampHeader) > self::MAX_CLOCK_SKEW_SECONDS) {
            return self::EXPIRED;
        }

        // Every secret is checked (no early exit) so timing does not reveal which one matched.
        $isMatch = false;
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            if (hash_equals(self::digest($secret, $timestampHeader, $method, $pathAndQuery), $match[1])) {
                $isMatch = true;
            }
        }

        return $isMatch ? self::VALID : self::INVALID;
    }

    private static function digest(string $secret, string $timestamp, string $method, string $pathAndQuery): string
    {
        return hash_hmac('sha256', $timestamp."\n".strtoupper($method)."\n".$pathAndQuery, $secret);
    }
}
