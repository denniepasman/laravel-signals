<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals;

use Dennieos\LaravelSignals\Console\HeartbeatCommand;
use Dennieos\LaravelSignals\Console\PruneCommand;
use Dennieos\LaravelSignals\Console\ScanLogsCommand;
use Dennieos\LaravelSignals\Console\StatusCommand;
use Dennieos\LaravelSignals\Console\SyncProvidersCommand;
use Dennieos\LaravelSignals\Contracts\ErrorProvider;
use Dennieos\LaravelSignals\Http\Controllers\EventsController;
use Dennieos\LaravelSignals\Http\Middleware\VerifyIntakeSignature;
use Dennieos\LaravelSignals\Logging\DennieosHandler;
use Dennieos\LaravelSignals\Logging\LogScanner;
use Dennieos\LaravelSignals\Providers\ConfiguredTableProvider;
use Dennieos\LaravelSignals\Providers\ProviderSync;
use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Support\EventBuilder;
use Dennieos\LaravelSignals\Support\LogFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SignalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dennieos-signals.php', 'dennieos-signals');

        $this->app->singleton(EventStore::class);
        $this->app->singleton(EventBuilder::class, fn (): EventBuilder => new EventBuilder(
            basePath: base_path(),
            userHashSalt: (string) config('dennieos-signals.user_hash_salt', ''),
            release: ($release = config('dennieos-signals.release')) === null ? null : (string) $release,
        ));
        $this->app->singleton(LogFiles::class, function (): LogFiles {
            $path = config('dennieos-signals.logs.path');

            return new LogFiles(is_string($path) && $path !== '' ? $path : storage_path('logs'));
        });
        $this->app->singleton(LogScanner::class, fn ($app): LogScanner => new LogScanner(
            files: $app->make(LogFiles::class),
            store: $app->make(EventStore::class),
            builder: $app->make(EventBuilder::class),
            timezone: (string) config('app.timezone', 'UTC'),
            quietSeconds: (int) config('dennieos-signals.logs.quiet_seconds', 300),
            chunkBytes: (int) config('dennieos-signals.logs.chunk_bytes', 2097152),
            maxEntryBytes: (int) config('dennieos-signals.logs.max_entry_bytes', 16777216),
        ));
        $this->app->singleton(DennieosHandler::class, fn ($app): DennieosHandler => new DennieosHandler(
            $app->make(EventStore::class),
            $app->make(EventBuilder::class),
            (string) config('app.timezone', 'UTC'),
        ));
        $this->app->singleton(ProviderSync::class, fn ($app): ProviderSync => new ProviderSync(
            $this->resolveProviders(),
            $app->make(EventStore::class),
            $app->make(EventBuilder::class),
        ));
    }

    public function boot(): void
    {
        // The migration always runs: empty tables are harmless, so activating later needs no extra step.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/dennieos-signals.php' => config_path('dennieos-signals.php')], 'dennieos-signals-config');
            $this->commands([HeartbeatCommand::class, ScanLogsCommand::class, SyncProvidersCommand::class, PruneCommand::class, StatusCommand::class]);
        }

        if (! Activation::isActive()) {
            return;
        }

        $this->registerRoutes();
        $this->registerSchedule();
        $this->attachHandler();
    }

    private function registerRoutes(): void
    {
        if (! config('dennieos-signals.route.enabled', true)) {
            return;
        }
        RateLimiter::for('dennieos-intake', fn (Request $request): Limit => Limit::perMinute((int) config('dennieos-signals.route.rate_limit_per_minute', 60))->by('dennieos-intake'));

        Route::middleware([...(array) config('dennieos-signals.route.middleware', []), VerifyIntakeSignature::class, 'throttle:dennieos-intake'])
            ->prefix((string) config('dennieos-signals.route.prefix', 'dennieos/intake/v1'))
            ->group(function (): void {
                Route::get('events', EventsController::class)->name('dennieos.intake.events');
            });
    }

    private function registerSchedule(): void
    {
        if (! config('dennieos-signals.schedule', true)) {
            return;
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('dennieos:heartbeat')->everyMinute();
            if (config('dennieos-signals.providers') !== []) {
                $schedule->command('dennieos:sync-providers')->everyMinute()->withoutOverlapping();
            }
            $schedule->command('dennieos:scan-logs')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('dennieos:prune')->dailyAt('03:30');
        });
    }

    private function attachHandler(): void
    {
        if (! config('dennieos-signals.capture_server_errors', true)) {
            return;
        }
        $this->app->booted(function (): void {
            try {
                $default = config('logging.default');
                $logger = $this->app->make('log')->channel(is_string($default) ? $default : null)->getLogger();
                if ($logger instanceof \Monolog\Logger) {
                    $logger->pushHandler($this->app->make(DennieosHandler::class));
                }
            } catch (\Throwable) {
                // A broken logging config is the app's problem to report; never fail the boot over it.
            }
        });
    }

    /** @return list<ErrorProvider> */
    private function resolveProviders(): array
    {
        $providers = [];
        foreach ((array) config('dennieos-signals.providers', []) as $entry) {
            $providers[] = match (true) {
                $entry instanceof ErrorProvider => $entry,
                is_string($entry) => $this->app->make($entry),
                is_array($entry) => new ConfiguredTableProvider($entry),
                default => throw new \InvalidArgumentException('Invalid dennieos-signals provider entry.'),
            };
        }

        return $providers;
    }
}
