<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

final class BrowserFamily
{
    /** Browser family and major version only ("Chrome 120"); never the full user agent. */
    public static function parse(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }
        $patterns = [
            'Edge' => '~Edg(?:e|A|iOS)?/(\d+)~',
            'Opera' => '~OPR/(\d+)~',
            'Firefox' => '~(?:Firefox|FxiOS)/(\d+)~',
            'Chrome' => '~(?:Chrome|CriOS)/(\d+)~',
            'Safari' => '~Version/(\d+)[\d.]* (?:Mobile/\S+ )?Safari/~',
        ];
        foreach ($patterns as $family => $pattern) {
            if (preg_match($pattern, $userAgent, $match) === 1) {
                return $family.' '.$match[1];
            }
        }

        return null;
    }
}
