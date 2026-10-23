<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Providers;

use Closure;
use Dennieos\LaravelSignals\Contracts\ErrorProvider;
use Dennieos\LaravelSignals\Support\BrowserFamily;
use Dennieos\LaravelSignals\Support\Levels;
use Illuminate\Support\Facades\DB;

/**
 * A table provider configured in config/dennieos-signals.php: any table with an increasing
 * cursor column, mapped field by field onto the contract (see the config for the field list).
 */
final class ConfiguredTableProvider implements ErrorProvider
{
    private const STRING_FIELDS = ['occurred_at', 'level', 'class', 'message', 'stack', 'route', 'method', 'release', 'command', 'fingerprint_hint', 'url_path', 'id'];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        foreach (['name', 'table', 'cursor_column'] as $required) {
            if (! isset($config[$required]) || ! is_string($config[$required]) || $config[$required] === '') {
                throw new \InvalidArgumentException("Table provider needs a '{$required}'.");
            }
        }
    }

    public function name(): string
    {
        return $this->config['name'];
    }

    public function fetch(?string $afterCursor, int $limit): ProviderBatch
    {
        $connection = $this->config['connection'] ?? config('dennieos-signals.connection');
        $query = DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table($this->config['table']);
        foreach ((array) ($this->config['where'] ?? []) as $column => $value) {
            $query->where($column, $value);
        }
        $cursorColumn = $this->config['cursor_column'];
        if ($afterCursor !== null) {
            $query->where($cursorColumn, '>', $afterCursor);
        }
        $rows = $query->orderBy($cursorColumn)->limit($limit)->get();

        $events = [];
        $next = $afterCursor;
        foreach ($rows as $row) {
            $cursorValue = (string) $row->{$cursorColumn};
            $next = $cursorValue;
            $event = $this->map($row, $cursorValue);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return new ProviderBatch($events, $next, $rows->count());
    }

    /** @return array<string, mixed>|null null = below warning, not sent */
    private function map(object $row, string $cursorValue): ?array
    {
        $value = fn (string $field): mixed => $this->value($row, $field);

        $level = Levels::fromName((string) ($value('level') ?? 'error'));
        if ($level === null) {
            return null;
        }
        $id = $value('id');
        $id = is_scalar($id) && (string) $id !== '' ? (string) $id : $cursorValue;
        $userAgent = $value('user_agent');
        $url = $value('url');

        $strings = [];
        foreach (self::STRING_FIELDS as $field) {
            $fieldValue = $value($field);
            $strings[$field] = is_scalar($fieldValue) || $fieldValue instanceof \Stringable ? (string) $fieldValue : null;
        }
        $status = $value('status');
        $userId = $value('user_id');

        return [
            'id' => $this->name().':'.$id,
            'occurred_at' => $strings['occurred_at'] ?? 'now',
            'level' => $level,
            'class' => $strings['class'],
            'message' => $strings['message'],
            'stack' => $strings['stack'],
            'fingerprint_hint' => $strings['fingerprint_hint'],
            'user_id' => is_scalar($userId) ? (string) $userId : null,
            'context' => [
                'route' => $strings['route'],
                'method' => $strings['method'],
                'status' => is_numeric($status) ? (int) $status : null,
                'release' => $strings['release'],
                'browser' => is_string($userAgent) ? BrowserFamily::parse($userAgent) : null,
                'url_path' => $strings['url_path'] ?? (is_string($url) ? $url : null),
                'command' => $strings['command'],
            ],
        ];
    }

    private function value(object $row, string $field): mixed
    {
        $mapped = $this->config['map'][$field] ?? null;
        if ($mapped instanceof Closure) {
            return $mapped($row);
        }
        if (is_string($mapped) && $mapped !== '') {
            return $row->{$mapped} ?? ($this->config['defaults'][$field] ?? null);
        }

        return $this->config['defaults'][$field] ?? null;
    }
}
