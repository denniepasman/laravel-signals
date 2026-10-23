<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\State;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

it('writes the scheduler heartbeat', function (): void {
    Carbon::setTestNow('2026-10-23 08:15:00');

    Artisan::call('dennieos:heartbeat');

    expect(State::get(State::SCHEDULER_LAST_RUN_AT))->toBe('2026-10-23T08:15:00Z');
});

it('schedules the heartbeat every minute, the scan, and the prune', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => $event->command.' '.$event->expression);

    expect($events->implode("\n"))
        ->toContain("dennieos:heartbeat * * * * *")
        ->toContain("dennieos:scan-logs */5 * * * *")
        ->toContain('dennieos:prune');
});

it('scans with the filter DennieOS sent', function (): void {
    writeLog($this->logDir(), 'laravel.log', logEntry('2026-10-23 08:00:00', 'error', 'default file'));
    writeLog($this->logDir(), 'custom.log', logEntry('2026-10-23 08:00:00', 'error', 'custom file'));
    State::put(State::LOG_FILTER, json_encode(['rules' => [['pattern' => 'custom.log', 'mode' => 'include', 'min_level' => 'error']]]));

    Artisan::call('dennieos:scan-logs');

    expect(storedMessages())->toBe(['custom file']);
});

it('prunes by the retention the client set', function (): void {
    storeEvent('old');
    storeEvent('new');
    Database::table('dennieos_error_events')->where('event_id', 'old')->update(['created_at' => Carbon::now()->subDays(31)]);

    Artisan::call('dennieos:prune');
    expect(storedEventIds())->toBe(['new']);

    config()->set('dennieos-signals.retention_days', 0);
    expect(Artisan::call('dennieos:prune'))->toBe(1);
});

it('reports the installation status', function (): void {
    $this->artisan('dennieos:status')
        ->expectsOutputToContain('Tables migrated: yes')
        ->expectsOutputToContain('Intake secret set: yes')
        ->assertExitCode(0);
});
