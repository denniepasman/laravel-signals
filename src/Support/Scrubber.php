<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

/**
 * Privacy scrubber for text and context, applied at the source before anything is stored.
 *
 * This is a port of the rules in DennieOS apps/web/src/domain/signals/scrub.ts (and the shared
 * redaction-rules.ts). DennieOS scrubs again at intake, so a rule missing here is caught there,
 * but a rule removed here would leak personal data into the client's own table: keep them in sync.
 * Pure and idempotent: scrub(scrub(x)) === scrub(x).
 *
 * The expressions are deliberately used without the /u flag: PHP's /u switches \w and \b to
 * Unicode, JavaScript's do not, and invalid UTF-8 in a log line would make preg_* return null.
 */
final class Scrubber
{
    public const REDACTED = '[redacted]';
    public const PERSONAL_REDACTED = '[personal]';
    public const TRUNCATED_MARKER = '…[truncated]';
    public const MAX_MESSAGE_LENGTH = 1000;
    public const MAX_STACK_LENGTH = 8000;

    private const MAX_CONTEXT_DEPTH = 4;
    private const MAX_CONTEXT_ENTRIES = 50;
    private const SETTLE_PASSES = 3;
    private const GIT_SHA_LENGTH = 40;

    private const HEADER = '~\b((?:set-)?cookie|(?:proxy-)?authorization|x-api-key|x-auth-token|x-csrf-token|x-xsrf-token)(["\']?)(\s*[:=]\s*)("[^"\n]*"|\'[^\'\n]*\'|(?!\s|\[redacted\])[^\r\n]*)~i';
    private const ASSIGNMENT = '~\b((?:access|refresh|id)?[-_]?token|secret|client[-_]?secret|password|passwd|api[-_]?key|apikey|signature|code)=([^&\s"\']+)~i';
    private const JSON_PAIR = '~"(password|passwd|secret|token|access_token|refresh_token|api_?key|signature)"\s*:\s*"[^"]*"~i';
    private const BEARER = '~\bBearer\s+[\w.\~+/-]+=*~i';
    private const JWT = '~\beyJ[\w-]+\.[\w-]+\.[\w-]+~';
    private const SUPABASE_KEY = '~\bsb_(secret|publishable)_[\w-]+~';
    private const QUERY_VALUE = '~([?&][\w[\].%-]+)=([^&\s#"\'<>]*)~';
    private const LONG_HEX = '~(?<![\w/.\\\\-])[0-9a-f]{32,}(?![\w/\\\\]|\.\w)~i';
    private const LONG_BASE64 = '~(?<![\w/.\\\\-])[A-Za-z0-9+_-]{40,}={0,2}(?![\w/\\\\]|\.\w)~';

    private const EMAIL = '~[^\s@<>()\[\]"\',;:]+@[^\s@<>()\[\]"\',;:]+\.[a-z]{2,}~i';
    private const UUID = '~\b([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\b~i';
    private const IBAN = '~(?<![\w-])[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?(?![\w-])~';
    private const BSN = '~(?<![\w.-])\d{9}(?![\w-]|\.\d)~';
    private const PHONE_CANDIDATE = '~(?<![\w.:/+-])(?:\+|00)?\(?\d[\d\s().-]{7,17}\d(?![\w-])~';
    private const DATE_LIKE = '~^\d{1,4}[-.]\d{1,2}[-.]\d{1,4}~';
    private const VERSION_BEFORE_IP = '~(?:\bv|\bversion\s|\bver\.?\s?)$~i';
    private const VERSION_LOOKBEHIND = 9;

    private const SENSITIVE_WORDS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'authorization', 'authorisation',
        'cookie', 'signature', 'credential', 'credentials', 'payload',
    ];
    private const SENSITIVE_PAIRS = ['api key', 'private key', 'session id'];
    private const SENSITIVE_KEYS = ['apikey', 'session', 'body', 'html', 'text', 'emailbody'];
    private const PERSONAL_WORDS = [
        'email', 'mail', 'phone', 'mobile', 'ip', 'iban', 'bsn', 'username', 'firstname', 'lastname',
    ];
    private const PERSONAL_WORD_PAIRS = ['user id', 'user name'];

    /** Text without credentials and personal data, cut to $maxLength characters (marker included). */
    public static function text(string $text, int $maxLength = self::MAX_MESSAGE_LENGTH): string
    {
        $current = mb_scrub($text, 'UTF-8');
        for ($pass = 0; $pass < self::SETTLE_PASSES; $pass++) {
            $current = self::scrubOnce($current, $maxLength);
        }

        return $current;
    }

    /**
     * A JSON-safe, bounded copy of $context with credentials and personal data removed.
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $allowedKeys  top-level keys to keep; null keeps every key
     * @return array<string, mixed>
     */
    public static function context(array $context, ?array $allowedKeys = null, int $maxStringLength = self::MAX_MESSAGE_LENGTH): array
    {
        if ($allowedKeys !== null) {
            $context = array_intersect_key($context, array_flip($allowedKeys));
        }

        return self::scrubEntries($context, 0, $maxStringLength);
    }

    /** The only form in which a user id leaves the application: HMAC-SHA256 with the source's salt. */
    public static function hashUserId(string $rawId, string $salt): string
    {
        if ($salt === '') {
            throw new \InvalidArgumentException('hashUserId needs a non-empty salt');
        }

        return hash_hmac('sha256', $rawId, $salt);
    }

    private static function scrubOnce(string $text, int $maxLength): string
    {
        $wasTruncated = str_ends_with($text, self::TRUNCATED_MARKER);
        $body = $wasTruncated ? mb_substr($text, 0, mb_strlen($text) - mb_strlen(self::TRUNCATED_MARKER)) : $text;

        $parts = preg_split(self::UUID, self::removeCredentials($body), -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = $parts === false ? [self::removeCredentials($body)] : $parts;
        $scrubbed = '';
        foreach ($parts as $index => $part) {
            $scrubbed .= $index % 2 === 1 ? $part : self::removePersonalData($part);
        }

        if (! $wasTruncated && mb_strlen($scrubbed) <= $maxLength) {
            return $scrubbed;
        }

        $keep = max(0, $maxLength - mb_strlen(self::TRUNCATED_MARKER));

        return mb_substr($scrubbed, 0, $keep).self::TRUNCATED_MARKER;
    }

    private static function removeCredentials(string $text): string
    {
        $r = self::REDACTED;
        $text = self::replace(self::HEADER, $text, function (array $m) use ($r): string {
            [, $name, $quote, $separator, $value] = $m;
            $separator = str_contains($separator, '=') ? ': ' : $separator;
            $first = $value[0] ?? '';
            $isQuoted = $first === '"' || $first === "'";

            return $name.$quote.$separator.($isQuoted ? $first.$r.$first : $r);
        });
        $text = self::replace(self::ASSIGNMENT, $text, fn (array $m): string => $m[1].'='.$r);
        $text = self::replace(self::JSON_PAIR, $text, fn (array $m): string => '"'.$m[1].'":"'.$r.'"');
        $text = self::replace(self::BEARER, $text, fn (): string => 'Bearer '.$r);
        $text = self::replace(self::JWT, $text, fn (): string => $r);
        $text = self::replace(self::SUPABASE_KEY, $text, fn (): string => $r);
        $text = self::replace(self::QUERY_VALUE, $text, fn (array $m): string => $m[1].'='.$r);
        $text = self::replace(self::LONG_HEX, $text, fn (array $m): string => strlen($m[0]) === self::GIT_SHA_LENGTH ? $m[0] : $r);

        return self::replace(self::LONG_BASE64, $text, fn (array $m): string => self::isSecretLookingBase64($m[0]) ? $r : $m[0]);
    }

    private static function removePersonalData(string $text): string
    {
        $p = self::PERSONAL_REDACTED;
        $octet = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';
        $ipv4 = '~(?<![\w.-])(?:'.$octet.'\.){3}'.$octet.'(?![\w-]|\.\d)~';
        $hextet = '[0-9a-f]{1,4}';
        $ipv6 = '~(?<![\w:.])(?:(?:'.$hextet.':){7}'.$hextet.'|(?:'.$hextet.':){1,7}:(?:'.$hextet.'(?::'.$hextet.'){0,6})?|::(?:'.$hextet.'(?::'.$hextet.'){0,6})?)(?![\w:.]*\w)~i';

        $text = self::replace(self::EMAIL, $text, fn (): string => $p);
        $text = self::replace(self::IBAN, $text, fn (array $m): string => self::isValidIban($m[0]) ? $p : $m[0]);
        $text = self::replaceIpv4($text, $ipv4, $p);
        $text = self::replace($ipv6, $text, fn (): string => $p);
        $text = self::replace(self::PHONE_CANDIDATE, $text, fn (array $m): string => self::isPhoneNumber($m[0]) ? $p : $m[0]);

        return self::replace(self::BSN, $text, fn (array $m): string => self::isValidBsn($m[0]) ? $p : $m[0]);
    }

    private static function replaceIpv4(string $text, string $pattern, string $replacement): string
    {
        $result = '';
        $last = 0;
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $text;
        }
        foreach ($matches[0] as [$match, $offset]) {
            $before = substr($text, max(0, $offset - self::VERSION_LOOKBEHIND), min($offset, self::VERSION_LOOKBEHIND));
            $result .= substr($text, $last, $offset - $last);
            $result .= preg_match(self::VERSION_BEFORE_IP, $before) === 1 ? $match : $replacement;
            $last = $offset + strlen($match);
        }

        return $result.substr($text, $last);
    }

    /** preg_replace_callback that never turns the text into null (a PCRE error keeps the text). */
    private static function replace(string $pattern, string $text, callable $callback): string
    {
        return preg_replace_callback($pattern, $callback, $text) ?? $text;
    }

    private static function isSecretLookingBase64(string $candidate): bool
    {
        return preg_match('~\d~', $candidate) === 1
            && preg_match('~[a-z]~', $candidate) === 1
            && preg_match('~[A-Z]~', $candidate) === 1;
    }

    private static function isValidIban(string $candidate): bool
    {
        $compact = preg_replace('~\s~', '', $candidate) ?? '';
        if (strlen($compact) < 15 || strlen($compact) > 34) {
            return false;
        }
        $rearranged = substr($compact, 4).substr($compact, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $value = ctype_digit($char) ? $char : (string) (ord($char) - 65 + 10);
            foreach (str_split($value) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    /** The Dutch 11-proof: 9a + 8b + … + 2h − i is a multiple of 11 (and not all zeros). */
    private static function isValidBsn(string $candidate): bool
    {
        $digits = array_map('intval', str_split($candidate));
        $count = count($digits);
        $sum = 0;
        foreach ($digits as $index => $digit) {
            $sum += $digit * ($index === $count - 1 ? -1 : $count - $index);
        }

        return $sum % 11 === 0 && array_filter($digits, fn (int $d): bool => $d !== 0) !== [];
    }

    private static function isPhoneNumber(string $candidate): bool
    {
        $digits = strlen(preg_replace('~\D~', '', $candidate) ?? '');
        $isInternational = str_starts_with($candidate, '+') || str_starts_with($candidate, '00');
        if ($isInternational) {
            return $digits >= 9 && $digits <= 15;
        }
        $startsWithZero = str_starts_with(preg_replace('~\D~', '', $candidate) ?? '', '0');

        // Dutch numbers: 10 digits behind a leading 0, separated by spaces or hyphens only (dots and
        // dates such as 01-02-2026 10:00 look the same otherwise).
        return $digits === 10
            && $startsWithZero
            && ! str_contains($candidate, '.')
            && preg_match(self::DATE_LIKE, $candidate) !== 1;
    }

    /** @return list<string> */
    private static function wordsOf(string $key): array
    {
        $spaced = preg_replace('~([a-z0-9])([A-Z])~', '$1 $2', $key) ?? $key;
        $words = preg_split('~[^a-z0-9]+~', strtolower($spaced)) ?: [];

        return array_values(array_filter($words, fn (string $w): bool => $w !== ''));
    }

    private static function isSensitiveKey(string $key): bool
    {
        $words = self::wordsOf($key);
        if (in_array(implode('', $words), self::SENSITIVE_KEYS, true)) {
            return true;
        }
        if (array_intersect($words, self::SENSITIVE_WORDS) !== []) {
            return true;
        }
        for ($i = 0; $i < count($words) - 1; $i++) {
            if (in_array($words[$i].' '.$words[$i + 1], self::SENSITIVE_PAIRS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function isPersonalKey(string $key): bool
    {
        $words = self::wordsOf($key);

        return array_intersect($words, self::PERSONAL_WORDS) !== []
            || in_array(implode(' ', $words), self::PERSONAL_WORD_PAIRS, true);
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return array<array-key, mixed>
     */
    private static function scrubEntries(array $record, int $depth, int $maxString): array
    {
        $result = [];
        foreach (array_slice($record, 0, self::MAX_CONTEXT_ENTRIES, true) as $key => $entry) {
            $key = (string) $key;
            if (self::isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
            } elseif (self::isPersonalKey($key)) {
                $result[$key] = self::PERSONAL_REDACTED;
            } else {
                $value = self::scrubValue($entry, $depth + 1, $maxString);
                if ($value !== null || $entry === null) {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    private static function scrubValue(mixed $value, int $depth, int $maxString): mixed
    {
        if (is_string($value)) {
            return self::text($value, $maxString);
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \Stringable) {
            return self::text((string) $value, $maxString);
        }
        if (! is_array($value) && ! is_object($value)) {
            return null; // resources are not stored
        }
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return '[truncated]';
        }
        $array = is_object($value) ? get_object_vars($value) : $value;
        if (array_is_list($array)) {
            $items = array_map(
                fn (mixed $item): mixed => self::scrubValue($item, $depth + 1, $maxString),
                array_slice($array, 0, self::MAX_CONTEXT_ENTRIES),
            );

            return count($array) > self::MAX_CONTEXT_ENTRIES ? [...$items, '[truncated]'] : $items;
        }

        return self::scrubEntries($array, $depth, $maxString);
    }
}
