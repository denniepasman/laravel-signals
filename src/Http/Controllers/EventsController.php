<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Http\Controllers;

use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Support\LogFiles;
use Dennieos\LaravelSignals\Support\LogFilter;
use Dennieos\LaravelSignals\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** GET /dennieos/intake/v1/events: contract v1, behind the signature middleware. */
final class EventsController
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_RESPONSE_BYTES = 5_000_000;
    private const HEARTBEAT_ALLOWANCE_BYTES = 60_000;

    public function __construct(private readonly EventStore $store, private readonly LogFiles $logFiles)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $cursorParam = $request->query('cursor');
        $limitParam = $request->query('limit');
        $filterParam = $request->query('log_filter');

        if ($limitParam !== null && (! is_string($limitParam) || preg_match('~^\d{1,4}$~', $limitParam) !== 1 || (int) $limitParam < 1 || (int) $limitParam > 500)) {
            return $this->error('limit_invalid', 400);
        }
        $limit = $limitParam === null ? self::DEFAULT_LIMIT : (int) $limitParam;

        $cursor = 0;
        if ($cursorParam !== null) {
            if (! is_string($cursorParam) || preg_match('~^\d{1,18}$~', $cursorParam) !== 1 || (int) $cursorParam > $this->store->maxId()) {
                return $this->error('cursor_unknown', 400);
            }
            $cursor = (int) $cursorParam;
        }
        $prunedUpTo = (int) State::get(State::PRUNED_UP_TO_ID);
        if ($cursorParam !== null && $cursor < $prunedUpTo) {
            return $this->error('cursor_pruned', 410);
        }

        if ($filterParam !== null) {
            $filter = is_string($filterParam) ? LogFilter::decode($filterParam) : null;
            if ($filter === null) {
                return $this->error('log_filter_invalid', 400);
            }
            // Signed with the request, so it comes from DennieOS; applied from the next scan-logs run.
            State::put(State::LOG_FILTER, json_encode($filter->toArray(), JSON_UNESCAPED_SLASHES) ?: '');
        }

        $rows = $this->store->after($cursor, $limit);
        $events = [];
        $bytes = self::HEARTBEAT_ALLOWANCE_BYTES;
        $nextCursor = $cursorParam === null ? (string) max($this->store->maxId(), $prunedUpTo) : (string) $cursor;
        foreach ($rows as $row) {
            $size = strlen((string) json_encode($row['event'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($events !== [] && $bytes + $size > self::MAX_RESPONSE_BYTES) {
                break;
            }
            $bytes += $size;
            $events[] = $row['event'];
            $nextCursor = (string) $row['cursor'];
        }
        // An empty list must encode as [], not as {}.
        return response()->json(
            ['events' => array_values($events), 'next_cursor' => $nextCursor, 'heartbeat' => $this->heartbeat()],
            200,
            ['Cache-Control' => 'no-store'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @return array<string, mixed> */
    private function heartbeat(): array
    {
        $heartbeat = [
            'scheduler_last_run_at' => State::get(State::SCHEDULER_LAST_RUN_AT),
            'writer_last_ok_at' => State::get(State::WRITER_LAST_OK_AT),
            'buffered' => 0,
            'dropped' => (int) State::get(State::DROPPED),
            'package_version' => Version::PACKAGE,
            'contract_version' => Version::CONTRACT,
        ];

        $files = $this->logFiles->list();
        $channel = LogFiles::detectChannel($files);
        if ($channel !== null) {
            $heartbeat['log_channel'] = $channel;
        }
        if ($files !== []) {
            $heartbeat['log_files'] = array_map(fn (array $file): array => [
                'path' => $file['path'],
                'size_bytes' => $file['size'],
                'modified_at' => Carbon::createFromTimestampUTC($file['mtime'])->format('Y-m-d\TH:i:s\Z'),
            ], array_slice($files, 0, LogFiles::HEARTBEAT_LIMIT));
        }

        return $heartbeat;
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => $code], $status, ['Cache-Control' => 'no-store']);
    }
}
