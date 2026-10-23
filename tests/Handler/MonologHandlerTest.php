<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Storage\Database;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;


function setRunningInConsole(bool $value): void
{
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), $value);
}

function firstEvent(): object
{
    return Database::table('dennieos_error_events')->orderBy('id')->first();
}

it('stores an error logged through the default channel, scrubbed', function (): void {
    Log::error('Mail failed for marieke@voorbeeld.nl', ['exception' => new RuntimeException('Mail failed for marieke@voorbeeld.nl')]);

    $event = firstEvent();

    expect(Database::table('dennieos_error_events')->count())->toBe(1)
        ->and($event->class)->toBe('RuntimeException')
        ->and($event->message)->toBe('Mail failed for [personal]')
        ->and($event->level)->toBe('error')
        ->and($event->stack)->toContain('MonologHandlerTest.php')
        ->and($event->stack)->not->toContain('marieke')
        ->and(strlen($event->fingerprint_hint))->toBe(40);
});

it('ignores records below error', function (): void {
    Log::warning('just a warning');
    Log::info('info');

    expect(Database::table('dennieos_error_events')->count())->toBe(0);
});

it('stores an error without an exception as a log message', function (): void {
    Log::critical('Queue is stuck');

    expect(firstEvent())->toMatchObject(['class' => 'LogMessage', 'message' => 'Queue is stuck', 'level' => 'critical']);
});

it('never stores the same entry twice when the scanner later reads it from laravel.log', function (): void {
    Log::error('Gateway timeout', ['exception' => new RuntimeException('Gateway timeout')]);
    Log::error('Second problem');
    expect(file_exists($this->logDir().'/laravel.log'))->toBeTrue();

    $result = scanLogs(null, time() + 600);

    expect($result['files_read'])->toBe(1)
        ->and($result['imported'])->toBe(0)
        ->and(Database::table('dennieos_error_events')->count())->toBe(2);
});

it('is the scanner that adds a warning the handler (error and up) never saw', function (): void {
    Log::warning('Disk almost full');
    Log::error('Boom');

    scanLogs(null, time() + 600);

    expect(storedMessages())->toBe(['Boom', 'Disk almost full']);
});

it('records the console command of an error in an artisan command', function (): void {
    $argv = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['artisan', 'subscriptions:renew', '--force'];
    try {
        Log::error('Renewal failed');
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(json_decode(firstEvent()->context, true)['command'])->toBe('subscriptions:renew');
});

it('records route, method, status, browser and a hashed user for an HTTP error', function (): void {
    setRunningInConsole(false);
    Route::get('/orders/{order}', fn () => throw new RuntimeException('Order broke'))->name('orders.show');

    $this->actingAs(new GenericUser(['id' => 42]))
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'])
        ->get('/orders/5?token=secret');

    $context = json_decode(firstEvent()->context, true);

    expect($context)->toMatchArray([
        'route' => 'orders.show',
        'method' => 'GET',
        'status' => 500,
        'browser' => 'Chrome 120',
        'url_path' => '/orders/:order',
        'user_hash' => hash_hmac('sha256', '42', 'test-salt'),
    ])->and(json_encode($context))->not->toContain('token');
});

it('does not record a user without a salt, and never accepts a ready-made user_hash', function (): void {
    $event = (new \Dennieos\LaravelSignals\Support\EventBuilder('/', ''))->build([
        'id' => 'x', 'occurred_at' => 'now', 'level' => 'error', 'user_id' => 42, 'context' => ['user_hash' => 'f'.str_repeat('0', 63)],
    ]);

    expect($event->context['user_hash'])->toBeNull();
});

it('never throws while the app is failing, even when the table is gone', function (): void {
    \Illuminate\Support\Facades\Schema::drop('dennieos_error_events');

    Log::error('still reaches laravel.log');

    expect(file_get_contents($this->logDir().'/laravel.log'))->toContain('still reaches laravel.log');
});
