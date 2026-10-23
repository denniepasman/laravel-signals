<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Logging\LogScanner;
use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Support\LogFilter;

/** One Laravel-format log entry, with the exception block and stack trace Laravel writes. */
function logEntry(string $time, string $level, string $message, bool $withTrace = false): string
{
    $upper = strtoupper($level);
    if (! $withTrace) {
        return "[{$time}] production.{$upper}: {$message}\n";
    }

    return "[{$time}] production.{$upper}: {$message} {\"exception\":\"[object] (RuntimeException(code: 0): {$message} at /var/www/app/app/Services/Pay.php:88)\n"
        ."[stacktrace]\n"
        ."#0 /var/www/app/app/Http/Controllers/PaymentController.php(41): App\\Services\\Pay->charge()\n"
        ."#1 /var/www/app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\PaymentController->store()\n"
        ."#2 {main}\n\"}\n";
}

function writeLog(string $directory, string $name, string $content, ?int $mtime = null): string
{
    $path = $directory.'/'.$name;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
    touch($path, $mtime ?? time() - 3600);

    return $path;
}

function appendLog(string $path, string $content, ?int $mtime = null): void
{
    file_put_contents($path, $content, FILE_APPEND);
    touch($path, $mtime ?? time() - 3600);
}

function scanLogs(?LogFilter $filter = null, ?int $now = null): array
{
    return app(LogScanner::class)->scan($filter ?? LogFilter::default(), $now ?? time());
}

function storedEventIds(): array
{
    return Database::table('dennieos_error_events')->orderBy('id')->pluck('event_id')->all();
}

function storedMessages(): array
{
    return Database::table('dennieos_error_events')->orderBy('id')->pluck('message')->all();
}

/** A GET signed like DennieOS signs it. */
function signedGet(object $test, string $pathAndQuery, ?string $secret = null, ?int $timestamp = null): \Illuminate\Testing\TestResponse
{
    $timestamp ??= \Illuminate\Support\Carbon::now()->getTimestamp();
    $signature = \Dennieos\LaravelSignals\Support\Signature::sign($secret ?? \Dennieos\LaravelSignals\Tests\TestCase::SECRET, $timestamp, 'GET', $pathAndQuery);

    return $test->withHeaders([
        'X-DennieOS-Timestamp' => (string) $timestamp,
        'X-DennieOS-Signature' => $signature,
        'X-DennieOS-Source' => '00000000-0000-4000-8000-000000000000',
        'User-Agent' => 'DennieOS-Intake/1',
    ])->get($pathAndQuery);
}

function storeEvent(string $id, string $message = 'boom', string $level = 'error'): void
{
    $builder = app(\Dennieos\LaravelSignals\Support\EventBuilder::class);
    app(\Dennieos\LaravelSignals\Storage\EventStore::class)->insert($builder->build([
        'id' => $id,
        'occurred_at' => '2026-10-23T08:14:03.512Z',
        'level' => $level,
        'class' => 'RuntimeException',
        'message' => $message,
        'stack' => '#0 app/Foo.php(1): bar()',
        'context' => ['route' => 'payments.store', 'method' => 'POST', 'status' => 500, 'url_path' => '/betalen/42'],
    ]));
}
