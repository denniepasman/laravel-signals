<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Tests;

use Dennieos\LaravelSignals\SignalsServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    public const PREVIOUS_SECRET = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    protected ?string $logDirectory = null;

    /** A private temporary storage/logs for this test. */
    protected function logDir(): string
    {
        if ($this->logDirectory === null) {
            $this->logDirectory = sys_get_temp_dir().'/dennieos-signals-'.bin2hex(random_bytes(6));
            File::ensureDirectoryExists($this->logDirectory);
        }

        return $this->logDirectory;
    }

    protected function tearDown(): void
    {
        if ($this->logDirectory !== null) {
            File::deleteDirectory($this->logDirectory);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [SignalsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.timezone', 'Europe/Amsterdam');
        $app['config']->set('dennieos-signals.secret', self::SECRET);
        $app['config']->set('dennieos-signals.previous_secret', self::PREVIOUS_SECRET);
        $app['config']->set('dennieos-signals.user_hash_salt', 'test-salt');
        $app['config']->set('dennieos-signals.capture_server_errors', false);
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single.path', $this->logDir().'/laravel.log');
        $app['config']->set('dennieos-signals.logs.path', $this->logDir());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
