<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Turns raw error fields from any producer (Monolog handler, log scanner, table provider) into one
 * contract event: relative paths, scrubbing, bounds, hashed user, local fingerprint hint.
 * Every producer goes through here, so scrubbing at the source cannot be forgotten.
 */
final class EventBuilder
{
    private const CONTEXT_KEYS = ['route', 'method', 'status', 'release', 'user_hash', 'browser', 'url_path', 'command', 'log_file'];

    public function __construct(
        private readonly string $basePath,
        private readonly string $userHashSalt = '',
        private readonly ?string $release = null,
    ) {
    }

    /**
     * @param  array{
     *     id: string,
     *     occurred_at: DateTimeInterface|string,
     *     level: string,
     *     class?: ?string,
     *     message?: ?string,
     *     stack?: ?string,
     *     fingerprint_hint?: ?string,
     *     context?: array<string, mixed>,
     *     user_id?: string|int|null,
     * }  $raw
     */
    public function build(array $raw): Event
    {
        $level = Levels::fromName($raw['level']) ?? Levels::ERROR;
        $message = $this->scrubText($raw['message'] ?? '', Scrubber::MAX_MESSAGE_LENGTH);
        $stack = isset($raw['stack']) && $raw['stack'] !== '' ? $this->scrubText($raw['stack'], Scrubber::MAX_STACK_LENGTH) : null;
        $class = mb_substr($this->scrubText($raw['class'] ?? '', 255), 0, 255);
        $class = $class === '' ? 'Error' : $class;

        $context = $this->buildContext($raw['context'] ?? [], $raw['user_id'] ?? null);

        $hint = $raw['fingerprint_hint'] ?? null;
        $hint = is_string($hint) && $hint !== '' ? substr($hint, 0, 128) : $this->fingerprintHint($class, $message, $stack);

        return new Event(
            id: strlen($raw['id']) <= 64 ? $raw['id'] : substr(hash('sha256', $raw['id']), 0, 40),
            occurredAt: $this->formatTime($raw['occurred_at']),
            level: $level,
            class: $class,
            message: $message,
            stack: $stack,
            fingerprintHint: $hint,
            context: $context,
        );
    }

    /** The source's own grouping key: class + normalised message + first frame outside vendor/. */
    public function fingerprintHint(string $class, string $message, ?string $stack): string
    {
        $normalised = preg_replace(
            ['~[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}~i', "~'[^']*'|\"[^\"]*\"~", '~\d+~'],
            ['<uuid>', '?', 'N'],
            $message,
        ) ?? $message;

        return substr(hash('sha256', $class."\n".$normalised."\n".$this->firstApplicationFrame($stack)), 0, 40);
    }

    private function firstApplicationFrame(?string $stack): string
    {
        foreach (preg_split('~\R~', $stack ?? '') ?: [] as $line) {
            if (preg_match('~^(?:#\d+ |at )(\S+?)(?:\(\d+\)|:\d+)~', $line, $match) === 1 && ! str_contains($match[1], 'vendor/')) {
                return $match[1];
            }
        }

        return '';
    }

    private function relativise(string $text): string
    {
        $base = rtrim($this->basePath, '/\\');

        return $base === '' ? $text : str_replace([$base.'/', $base.'\\'], '', $text);
    }

    private function scrubText(string $text, int $maxLength): string
    {
        return Scrubber::text($this->relativise($text), $maxLength);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildContext(array $input, string|int|null $userId): array
    {
        $input['release'] ??= $this->release;
        // A caller can never hand over a readable or ready-made user value: only the hash computed
        // here from the raw id and the source's salt is kept.
        unset($input['user_hash']);
        $userHash = ($userId !== null && $userId !== '' && $this->userHashSalt !== '')
            ? Scrubber::hashUserId((string) $userId, $this->userHashSalt)
            : null;
        if (isset($input['url_path']) && is_string($input['url_path'])) {
            $input['url_path'] = UrlPath::normalise($input['url_path']);
        }
        $input = Scrubber::context($input, self::CONTEXT_KEYS, 255);
        // The hash is hex and would be redacted as a secret by the long-hex rule; it is not one.
        $input['user_hash'] = $userHash;

        $context = [];
        foreach (['route', 'method', 'status', 'release', 'user_hash', 'browser', 'url_path'] as $key) {
            $context[$key] = $input[$key] ?? null;
        }
        $context['status'] = is_int($context['status']) ? $context['status'] : null;
        foreach (['command', 'log_file'] as $optional) {
            if (isset($input[$optional]) && is_string($input[$optional])) {
                $context[$optional] = $input[$optional];
            }
        }

        return $context;
    }

    private function formatTime(DateTimeInterface|string $time): string
    {
        if (is_string($time)) {
            $time = new DateTimeImmutable($time);
        }

        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
