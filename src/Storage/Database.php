<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Database
{
    public static function connection(): ConnectionInterface
    {
        $name = config('dennieos-signals.connection');

        return DB::connection(is_string($name) && $name !== '' ? $name : null);
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }
}
