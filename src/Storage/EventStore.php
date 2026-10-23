<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Storage;

use Dennieos\LaravelSignals\Support\Event;
use Illuminate\Support\Carbon;

final class EventStore
{
    /** Stores an event once (the stable id is unique); returns whether it was new. Throws on a database failure. */
    public function insert(Event $event): bool
    {
        $inserted = Database::table('dennieos_error_events')->insertOrIgnore([
            'event_id' => $event->id,
            'occurred_at' => substr(str_replace(['T', 'Z'], [' ', ''], $event->occurredAt), 0, 23),
            'level' => $event->level,
            'class' => $event->class,
            'message' => $event->message,
            'stack' => $event->stack,
            'fingerprint_hint' => $event->fingerprintHint,
            'context' => json_encode($event->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => Carbon::now(),
        ]);
        State::touch(State::WRITER_LAST_OK_AT);

        return $inserted > 0;
    }

    /** For code that runs while the app is failing (the Monolog handler): never throws, counts what it lost. */
    public function insertSafely(Event $event): void
    {
        try {
            $this->insert($event);
        } catch (\Throwable) {
            try {
                State::increment(State::DROPPED);
            } catch (\Throwable) {
                // The database itself is down; there is nowhere left to count.
            }
        }
    }

    /**
     * Events after $cursor in ascending order.
     *
     * @return list<array{cursor: int, event: array<string, mixed>}>
     */
    public function after(int $cursor, int $limit): array
    {
        $rows = Database::table('dennieos_error_events')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $rows->map(function (object $row): array {
            $context = json_decode((string) $row->context, true);

            return [
                'cursor' => (int) $row->id,
                'event' => [
                    'id' => (string) $row->event_id,
                    'occurred_at' => Carbon::parse((string) $row->occurred_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                    'level' => (string) $row->level,
                    'class' => (string) $row->class,
                    'message' => (string) $row->message,
                    'stack' => $row->stack === null ? null : (string) $row->stack,
                    'fingerprint_hint' => $row->fingerprint_hint === null ? null : (string) $row->fingerprint_hint,
                    'context' => is_array($context) ? $context : [],
                ],
            ];
        })->all();
    }

    public function maxId(): int
    {
        return (int) Database::table('dennieos_error_events')->max('id');
    }

    /** Deletes events older than $days; remembers the highest pruned id so a stale cursor is answered with 410. */
    public function prune(int $days): int
    {
        $before = Carbon::now()->subDays($days);
        $highest = (int) Database::table('dennieos_error_events')->where('created_at', '<', $before)->max('id');
        if ($highest === 0) {
            return 0;
        }
        $deleted = Database::table('dennieos_error_events')->where('id', '<=', $highest)->delete();
        State::put(State::PRUNED_UP_TO_ID, (string) max($highest, (int) State::get(State::PRUNED_UP_TO_ID)));

        return $deleted;
    }
}
