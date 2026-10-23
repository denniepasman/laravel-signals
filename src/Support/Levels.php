<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

/** The three contract levels; everything below warning is never sent. */
final class Levels
{
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    private const RANK = [self::WARNING => 1, self::ERROR => 2, self::CRITICAL => 3];

    /** Maps a Monolog / syslog / app level name to a contract level; null = below warning or unknown. */
    public static function fromName(string $name): ?string
    {
        $name = strtolower(trim($name));
        if (ctype_digit($name)) { // Monolog numeric levels: 300 warning, 400 error, 500+ critical and up
            return match (true) {
                (int) $name >= 500 => self::CRITICAL,
                (int) $name >= 400 => self::ERROR,
                (int) $name >= 300 => self::WARNING,
                default => null,
            };
        }

        return match ($name) {
            'warning', 'warn' => self::WARNING,
            'error', 'err' => self::ERROR,
            'critical', 'crit', 'alert', 'emergency', 'emerg', 'fatal' => self::CRITICAL,
            default => null,
        };
    }

    public static function isValid(string $level): bool
    {
        return isset(self::RANK[$level]);
    }

    public static function atLeast(string $level, string $minimum): bool
    {
        return (self::RANK[$level] ?? 0) >= (self::RANK[$minimum] ?? 1);
    }
}
