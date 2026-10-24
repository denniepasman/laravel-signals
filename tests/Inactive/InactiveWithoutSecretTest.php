<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('registers the scheduled commands but never runs them', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'dennieos:'));

    expect($events)->not->toBeEmpty()
        ->and($events->filter(fn ($event): bool => $event->filtersPass($this->app)))->toBeEmpty();

    config()->set('dennieos-signals.secret', TestCase::SECRET);

    expect($events->filter(fn ($event): bool => $event->filtersPass($this->app)))->not->toBeEmpty();
});

it('registers the intake route so a cached route file always has it, and answers an empty 404', function (): void {
    expect(Route::has('dennieos.intake.events'))->toBeTrue();

    $response = $this->get('/dennieos/intake/v1/events');
    $response->assertNotFound();
    expect($response->getContent())->toBe('');
});

it('answers an empty 404 to a signed request too, without touching the database', function (): void {
    \Illuminate\Support\Facades\DB::enableQueryLog();

    $response = signedGet($this, '/dennieos/intake/v1/events?limit=10');

    $response->assertNotFound();
    expect($response->getContent())->toBe('')
        ->and(\Illuminate\Support\Facades\DB::getQueryLog())->toBe([]);
});

it('becomes active at runtime when the secret is set, with no re-registration', function (): void {
    $this->get('/dennieos/intake/v1/events')->assertNotFound();

    config()->set('dennieos-signals.secret', TestCase::SECRET);

    $this->get('/dennieos/intake/v1/events')->assertStatus(401);
    signedGet($this, '/dennieos/intake/v1/events?limit=10')->assertOk()->assertJsonPath('events', []);
});

it('survives a route:cache built without a secret', function (): void {
    $compiled = $this->app['router']->getRoutes()->compile();
    $this->app['router']->setCompiledRoutes($compiled);

    expect(Route::has('dennieos.intake.events'))->toBeTrue();
    $this->get('/dennieos/intake/v1/events')->assertNotFound();

    config()->set('dennieos-signals.secret', TestCase::SECRET);
    signedGet($this, '/dennieos/intake/v1/events?limit=10')->assertOk();
});

it('starts storing server errors at runtime once the secret is set', function (): void {
    config()->set('dennieos-signals.secret', TestCase::SECRET);
    Log::error('boom after the secret');

    expect(Database::table('dennieos_error_events')->count())->toBe(1);
});

it('attaches a silent Monolog handler and stores nothing', function (): void {
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

it('tells a stale config cache apart when .env holds a secret the cache lacks', function (): void {
    $dir = sys_get_temp_dir().'/dennieos-signals-env-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/.env', 'DENNIEOS_INTAKE_SECRET='.TestCase::SECRET."\n");
    file_put_contents($dir.'/config.php', '<?php return [];');
    $this->app->useEnvironmentPath($dir);
    $this->app->loadEnvironmentFrom('.env');
    fakeCachedConfig($this->app, $dir);

    try {
        $this->artisan('dennieos:status')
            ->expectsOutputToContain('inactive:')
            ->expectsOutputToContain('run php artisan config:cache')
            ->doesntExpectOutputToContain(TestCase::SECRET)
            ->assertExitCode(0);
    } finally {
        fakeCachedConfig($this->app, null);
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
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
