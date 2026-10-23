<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Storage;

use Illuminate\Support\Carbon;

/** Small key/value state of the package: heartbeat times, counters, the received log filter. */
final class State
{
    public const SCHEDULER_LAST_RUN_AT = 'scheduler_last_run_at';
    public const WRITER_LAST_OK_AT = 'writer_last_ok_at';
    public const DROPPED = 'dropped';
    public const LOG_FILTER = 'log_filter';
    public const PRUNED_UP_TO_ID = 'pruned_up_to_id';

    public static function get(string $key): ?string
    {
        $value = Database::table('dennieos_state')->where('key', $key)->value('value');

        return is_string($value) ? $value : null;
    }

    public static function put(string $key, string $value): void
    {
        Database::table('dennieos_state')->upsert(
            [['key' => $key, 'value' => $value, 'updated_at' => Carbon::now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    public static function increment(string $key, int $by = 1): void
    {
        self::put($key, (string) ((int) self::get($key) + $by));
    }

    public static function touch(string $key): void
    {
        self::put($key, Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'));
    }
}
