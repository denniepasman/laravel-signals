<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Logging;

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Support\EventBuilder;
use Dennieos\LaravelSignals\Support\Levels;
use Dennieos\LaravelSignals\Support\LogEntryParser;
use Dennieos\LaravelSignals\Support\LogFiles;
use Dennieos\LaravelSignals\Support\LogFilter;
use Illuminate\Support\Carbon;

/**
 * Reads the included files of storage/logs into the event table, for entries that bypassed the
 * Monolog handler. Works for the `single` and the `daily` channel and never imports an entry twice:
 *
 *  - a durable cursor per file: inode, byte offset and hash of the first line. A different inode or
 *    first line, or a size below the offset, means rotated or truncated: restart that file at 0;
 *  - a stable id per entry (see LogEntryParser), unique in the table, so a restart, a copy or a
 *    switch between channels cannot double an entry;
 *  - only complete entries: header up to the next header, stack trace included. The last entry of
 *    a file is read only once a newer header follows, or the file has been quiet long enough.
 */
final class LogScanner
{
    private const FIRST_LINE_BYTES = 4096;
    private const MAX_ROUNDS = 100;
    private const TAIL_BYTES = 256;

    public function __construct(
        private readonly LogFiles $files,
        private readonly EventStore $store,
        private readonly EventBuilder $builder,
        private readonly string $timezone,
        private readonly int $quietSeconds = 300,
        private readonly int $chunkBytes = 2097152,
        private readonly int $maxEntryBytes = 16777216,
    ) {
    }

    /** @return array{files_read: int, imported: int, failed: int} */
    public function scan(LogFilter $filter, ?int $now = null): array
    {
        $now ??= Carbon::now()->getTimestamp();
        $result = ['files_read' => 0, 'imported' => 0, 'failed' => 0];
        $found = $this->files->list();

        // A file deleted by retention takes its cursor with it.
        $paths = array_column($found, 'path');
        Database::table('dennieos_log_cursors')->whereNotIn('path', $paths ?: [''])->delete();

        foreach ($found as $file) {
            $minimum = $filter->minimumLevelFor($file['path']);
            if ($minimum === null) {
                continue;
            }
            try {
                $imported = $this->scanFile($file, $minimum, $now);
            } catch (\Throwable) {
                // A failing file must not stop the others; its cursor did not move, so it is retried.
                $result['failed']++;

                continue;
            }
            $result['files_read']++;
            $result['imported'] += $imported;
        }

        return $result;
    }

    /** @param array{path: string, absolute: string, size: int, mtime: int, inode: ?int} $file */
    private function scanFile(array $file, string $minimum, int $now): int
    {
        $cursor = Database::table('dennieos_log_cursors')->where('path', $file['path'])->first();
        $firstLineHash = $this->hashFirstLine($file['absolute']);

        $offset = 0;
        if ($cursor !== null
            && ($cursor->inode === null ? null : (int) $cursor->inode) === $file['inode']
            && (string) $cursor->first_line_hash === $firstLineHash
            && $file['size'] >= (int) $cursor->offset
            && (string) $cursor->tail_hash === $this->hashTail($file['absolute'], (int) $cursor->offset)) {
            $offset = (int) $cursor->offset;
        }

        $imported = 0;
        for ($round = 0; $round < self::MAX_ROUNDS && $offset < $file['size']; $round++) {
            [$consumed, $count] = $this->readRound($file, $offset, $minimum, $now);
            $imported += $count;
            $offset += $consumed;
            if ($consumed === 0) {
                break;
            }
        }

        $this->saveCursor($file, $offset, $firstLineHash, $this->hashTail($file['absolute'], $offset));

        return $imported;
    }

    /** @return array{0: int, 1: int} bytes consumed and events imported */
    private function readRound(array $file, int $offset, string $minimum, int $now): array
    {
        $length = min($this->chunkBytes, $file['size'] - $offset);
        while (true) {
            $buffer = $this->readBytes($file['absolute'], $offset, $length);
            $atEnd = $offset + strlen($buffer) >= $file['size'];
            preg_match_all(LogEntryParser::HEADER, $buffer, $matches, PREG_OFFSET_CAPTURE);
            $starts = array_map(fn (array $m): int => $m[1], $matches[0]);

            $lastIsComplete = ($atEnd && $now - $file['mtime'] >= $this->quietSeconds) || $length >= $this->maxEntryBytes;
            if (count($starts) <= 1 && ! $atEnd && $length < $this->maxEntryBytes) {
                $length = min($length * 2, $this->maxEntryBytes, $file['size'] - $offset);

                continue; // one entry larger than the chunk: read more
            }
            break;
        }

        if ($starts === []) {
            // Leading junk without a header: skip it only once the file is quiet (it may be half a header).
            if (! $lastIsComplete) {
                return [0, 0];
            }
            $newline = strrpos($buffer, "\n");

            return [$newline === false ? 0 : $newline + 1, 0]; // an unfinished last line may become a header
        }

        $end = strlen($buffer);
        if ($lastIsComplete && $atEnd && ! str_ends_with($buffer, "\n")) {
            // A quiet file whose last line was never finished: that half line is not an entry.
            $newline = strrpos($buffer, "\n");
            $end = $newline === false ? 0 : $newline + 1;
            $starts = array_values(array_filter($starts, fn (int $start): bool => $start < $end));
            if ($starts === []) {
                return [0, 0];
            }
        }

        $boundaries = [...$starts, $end];
        $completeCount = $lastIsComplete ? count($starts) : count($starts) - 1;
        if ($completeCount < 1) {
            return [0, 0];
        }

        $imported = 0;
        Database::connection()->transaction(function () use ($buffer, $boundaries, $completeCount, $file, $minimum, &$imported): void {
            for ($i = 0; $i < $completeCount; $i++) {
                $text = rtrim(substr($buffer, $boundaries[$i], $boundaries[$i + 1] - $boundaries[$i]), "\r\n");
                $parsed = LogEntryParser::parse($text, $this->timezone);
                if ($parsed === null || ! Levels::atLeast($parsed['level'], $minimum)) {
                    continue;
                }
                $event = $this->builder->build([
                    ...$parsed,
                    'context' => ['log_file' => $file['path']],
                ]);
                if ($this->store->insert($event)) {
                    $imported++;
                }
            }
        });

        return [$boundaries[$completeCount], $imported];
    }

    private function saveCursor(array $file, int $offset, string $firstLineHash, string $tailHash): void
    {
        Database::table('dennieos_log_cursors')->upsert(
            [[
                'path' => $file['path'],
                'inode' => $file['inode'],
                'offset' => $offset,
                'first_line_hash' => $firstLineHash,
                'tail_hash' => $tailHash,
                'size' => $file['size'],
                'updated_at' => Carbon::now(),
            ]],
            ['path'],
            ['inode', 'offset', 'first_line_hash', 'tail_hash', 'size', 'updated_at'],
        );
    }

    /** Hash of the (up to) 256 bytes just before $offset; a file rewritten in place no longer matches. */
    private function hashTail(string $absolute, int $offset): string
    {
        if ($offset === 0) {
            return '';
        }
        $start = max(0, $offset - self::TAIL_BYTES);

        return sha1($this->readBytes($absolute, $start, $offset - $start));
    }

    private function hashFirstLine(string $absolute): string
    {
        $head = $this->readBytes($absolute, 0, self::FIRST_LINE_BYTES);
        $newline = strpos($head, "\n");

        return $head === '' ? '' : sha1($newline === false ? $head : substr($head, 0, $newline));
    }

    private function readBytes(string $absolute, int $offset, int $length): string
    {
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('log file not readable');
        }
        try {
            fseek($handle, $offset);

            return (string) fread($handle, max(1, $length));
        } finally {
            fclose($handle);
        }
    }
}
