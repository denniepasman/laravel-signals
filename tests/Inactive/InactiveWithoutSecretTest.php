<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('registers no scheduled commands', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

    expect($commands)->not->toContain('dennieos:');
});

it('registers no intake route', function (): void {
    expect(Route::has('dennieos.intake.events'))->toBeFalse();
    $this->get('/dennieos/intake/v1/events')->assertNotFound();
});

it('does not attach the Monolog handler and stores nothing', function (): void {
    Log::error('boom without a secret');

    expect(Database::table('dennieos_error_events')->count())->toBe(0);
});

it('writes nothing to the database from any command', function (): void {
    writeLog($this->logDir(), 'laravel.log', logEntry('2026-10-23 08:00:00', 'error', 'in the log'));

    foreach (['heartbeat', 'scan-logs', 'sync-providers', 'prune'] as $name) {
        expect(Artisan::call("dennieos:{$name}"))->toBe(0);
    }

    expect(Database::table('dennieos_error_events')->count())->toBe(0)
        ->and(State::get(State::SCHEDULER_LAST_RUN_AT))->toBeNull();
});

it('says it is inactive in dennieos:status and exits 0', function (): void {
    $this->artisan('dennieos:status')
        ->expectsOutputToContain('inactive: DENNIEOS_INTAKE_SECRET is not set')
        ->assertExitCode(0);
});

it('stays inactive for an empty or blank secret', function (string $secret): void {
    config()->set('dennieos-signals.secret', $secret);

    $this->artisan('dennieos:status')->expectsOutputToContain('inactive:')->assertExitCode(0);
})->with(['', '   ']);

it('is active when the secret is set', function (): void {
    expect(TestCase::SECRET)->not->toBe('');
    config()->set('dennieos-signals.secret', TestCase::SECRET);

    Artisan::call('dennieos:heartbeat');

    expect(State::get(State::SCHEDULER_LAST_RUN_AT))->not->toBeNull();
});
