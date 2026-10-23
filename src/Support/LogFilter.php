<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

/**
 * Which files in storage/logs the scanner reads, and from which level.
 *
 * Rules are globs relative to storage/logs (`*` = anything but `/`, `**` = anything, `?` = one
 * character). A file is read when at least one include rule matches and no exclude rule matches
 * (exclude wins); when several include rules match, the lowest minimum level applies. Files that
 * match nothing are opt-in and not read.
 */
final class LogFilter
{
    /** @param list<array{pattern: string, mode: string, min_level: ?string}> $rules */
    private function __construct(private readonly array $rules)
    {
    }

    /** The rules used until DennieOS sends a log_filter (contract: default rules). */
    public static function default(): self
    {
        return new self([
            ['pattern' => 'laravel.log', 'mode' => 'include', 'min_level' => Levels::WARNING],
            ['pattern' => 'laravel-*.log', 'mode' => 'include', 'min_level' => Levels::WARNING],
            ['pattern' => 'deploy-*.log', 'mode' => 'exclude', 'min_level' => null],
        ]);
    }

    /** @param array<mixed> $decoded `{ "rules": [ { pattern, mode, min_level? } ] }` */
    public static function fromArray(array $decoded): ?self
    {
        $rawRules = $decoded['rules'] ?? null;
        if (! is_array($rawRules) || count($rawRules) > 100) {
            return null;
        }
        $rules = [];
        foreach ($rawRules as $rule) {
            if (! is_array($rule) || ! is_string($rule['pattern'] ?? null) || $rule['pattern'] === '' || strlen($rule['pattern']) > 255) {
                return null;
            }
            $mode = $rule['mode'] ?? null;
            if ($mode !== 'include' && $mode !== 'exclude') {
                return null;
            }
            $minLevel = $rule['min_level'] ?? null;
            if ($minLevel !== null && (! is_string($minLevel) || ! Levels::isValid($minLevel))) {
                return null;
            }
            $rules[] = [
                'pattern' => $rule['pattern'],
                'mode' => $mode,
                'min_level' => $mode === 'include' ? ($minLevel ?? Levels::WARNING) : null,
            ];
        }

        return new self($rules);
    }

    /** Decodes the `log_filter` query parameter (base64url of JSON); null when it is not valid. */
    public static function decode(string $encoded): ?self
    {
        if ($encoded === '' || preg_match('~^[A-Za-z0-9_-]+={0,2}$~', $encoded) !== 1 || strlen($encoded) > 16384) {
            return null;
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::fromArray($decoded) : null;
    }

    /** @return array{rules: list<array{pattern: string, mode: string, min_level?: string}>} */
    public function toArray(): array
    {
        return ['rules' => array_map(
            fn (array $rule): array => array_filter($rule, fn (mixed $v): bool => $v !== null),
            $this->rules,
        )];
    }

    /** The minimum level to read from $relativePath, or null when the file is not included. */
    public function minimumLevelFor(string $relativePath): ?string
    {
        $minimum = null;
        foreach ($this->rules as $rule) {
            if (! self::matches($rule['pattern'], $relativePath)) {
                continue;
            }
            if ($rule['mode'] === 'exclude') {
                return null;
            }
            $level = $rule['min_level'] ?? Levels::WARNING;
            if ($minimum === null || ! Levels::atLeast($level, $minimum)) {
                $minimum = $level;
            }
        }

        return $minimum;
    }

    public static function matches(string $pattern, string $path): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $regex .= '.*';
                    $i++;
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '~');
            }
        }

        return preg_match('~^'.$regex.'$~', $path) === 1;
    }
}
