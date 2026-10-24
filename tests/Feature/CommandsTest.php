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

it('reports the route and the config cache in the status', function (): void {
    $this->artisan('dennieos:status')
        ->expectsOutputToContain('Route registered: yes')
        ->expectsOutputToContain('Config cached: no')
        ->assertExitCode(0);
});

it('reports a missing route with the fix and exits 1', function (): void {
    $routes = new \Illuminate\Routing\RouteCollection();
    $this->app['router']->setRoutes($routes);

    $this->artisan('dennieos:status')
        ->expectsOutputToContain('Route registered: NO (run php artisan route:clear && php artisan route:cache)')
        ->assertExitCode(1);
});

it('says when the route is disabled on purpose', function (): void {
    config()->set('dennieos-signals.route.enabled', false);
    $this->app['router']->setRoutes(new \Illuminate\Routing\RouteCollection());

    $this->artisan('dennieos:status')
        ->expectsOutputToContain('Route registered: disabled')
        ->assertExitCode(0);
});

it('detects a cached config whose secret differs from .env without printing either', function (): void {
    $dir = sys_get_temp_dir().'/dennieos-signals-env-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $other = str_repeat('9', 64);
    file_put_contents($dir.'/.env', "DENNIEOS_INTAKE_SECRET={$other}\n");
    file_put_contents($dir.'/config.php', '<?php return [];');
    $this->app->useEnvironmentPath($dir);
    $this->app->loadEnvironmentFrom('.env');
    fakeCachedConfig($this->app, $dir);

    try {
        $this->artisan('dennieos:status')
            ->expectsOutputToContain('Config cached: yes')
            ->expectsOutputToContain('Config cache is stale')
            ->doesntExpectOutputToContain($other)
            ->doesntExpectOutputToContain(\Dennieos\LaravelSignals\Tests\TestCase::SECRET)
            ->assertExitCode(1);
    } finally {
        fakeCachedConfig($this->app, null);
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
});

it('accepts a cached config that matches .env', function (): void {
    $dir = sys_get_temp_dir().'/dennieos-signals-env-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/.env', 'DENNIEOS_INTAKE_SECRET='.\Dennieos\LaravelSignals\Tests\TestCase::SECRET."\n");
    file_put_contents($dir.'/config.php', '<?php return [];');
    $this->app->useEnvironmentPath($dir);
    $this->app->loadEnvironmentFrom('.env');
    fakeCachedConfig($this->app, $dir);

    try {
        $this->artisan('dennieos:status')
            ->expectsOutputToContain('Config cached: yes')
            ->doesntExpectOutputToContain('stale')
            ->assertExitCode(0);
    } finally {
        fakeCachedConfig($this->app, null);
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
});
