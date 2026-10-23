<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses one Laravel log entry (`[2026-10-23 08:14:03] production.ERROR: message {context}` plus
 * continuation lines such as the stack trace) into raw contract fields.
 *
 * The stable id is a hash of timestamp + level + message + first frame: content, not file position,
 * so it survives rotation, copytruncate, a copy of the file and a switch between the `single` and
 * `daily` channels, and it equals the id the Monolog handler computes for the same record.
 */
final class LogEntryParser
{
    public const HEADER = '~^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([^\s:]*)\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): ?~m';

    /**
     * @return array{id: string, occurred_at: string, level: string, class: string, message: string, stack: ?string}|null
     */
    public static function parse(string $entry, string $timezone): ?array
    {
        if (preg_match(self::HEADER, $entry, $header, PREG_OFFSET_CAPTURE) !== 1 || $header[0][1] !== 0) {
            return null;
        }
        [$timestamp, $levelName] = [$header[1][0], strtolower($header[3][0])];
        $level = Levels::fromName($levelName);
        if ($level === null) {
            return null;
        }

        $body = substr($entry, strlen($header[0][0]));
        $newline = strpos($body, "\n");
        $firstLine = rtrim($newline === false ? $body : substr($body, 0, $newline), "\r");
        $continuation = $newline === false ? '' : substr($body, $newline + 1);

        $idMessage = trim((string) preg_replace('~( \[\])+\s*$~', '', $firstLine));
        $firstFrame = preg_match('~^#0 [^\n]*~m', $continuation, $frame) === 1 ? rtrim($frame[0], "\r") : '';
        $id = substr(hash('sha256', $timestamp."\n".$levelName."\n".$idMessage."\n".$firstFrame), 0, 40);

        $parsed = self::parseException($body) ?? [
            'class' => 'LogMessage',
            'message' => self::stripContext($firstLine).($continuation === '' ? '' : "\n".rtrim($continuation)),
            'stack' => null,
        ];

        return [
            'id' => $id,
            'occurred_at' => self::toIso($timestamp, $timezone),
            'level' => $level,
            'class' => $parsed['class'],
            'message' => $parsed['message'],
            'stack' => $parsed['stack'],
        ];
    }

    /** @return array{class: string, message: string, stack: ?string}|null */
    private static function parseException(string $body): ?array
    {
        $withTrace = '~\[object\] \(([A-Za-z_\\\\][\w\\\\]*)\(code: [^)]*\): (.*?) at (\S+?):(\d+)\)\R\[stacktrace\]\R(.*)$~s';
        $withoutTrace = '~\[object\] \(([A-Za-z_\\\\][\w\\\\]*)\(code: [^)]*\): (.*?) at (\S+?):(\d+)\)~s';

        if (preg_match($withTrace, $body, $m) === 1) {
            $trace = preg_replace('~"\}\s*(\[\] ?)*\s*$~', '', rtrim($m[5])) ?? $m[5];

            return ['class' => $m[1], 'message' => $m[2], 'stack' => 'at '.$m[3].':'.$m[4]."\n".trim($trace)];
        }
        if (preg_match($withoutTrace, $body, $m) === 1) {
            return ['class' => $m[1], 'message' => $m[2], 'stack' => 'at '.$m[3].':'.$m[4]];
        }

        return null;
    }

    /** The message part of a first line, without the trailing JSON context. */
    private static function stripContext(string $firstLine): string
    {
        $cut = preg_split('~ (?:\{"|\[\{|\[\])~', $firstLine, 2);

        return trim($cut[0] ?? $firstLine);
    }

    private static function toIso(string $timestamp, string $timezone): string
    {
        try {
            $time = new DateTimeImmutable($timestamp, new DateTimeZone($timezone));
        } catch (\Exception) {
            $time = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
