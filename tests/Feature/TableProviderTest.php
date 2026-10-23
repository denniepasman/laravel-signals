<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Contracts\ErrorProvider;
use Dennieos\LaravelSignals\Providers\ProviderBatch;
use Dennieos\LaravelSignals\Providers\ProviderSync;
use Dennieos\LaravelSignals\Storage\Database;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The shape of TestMyWebsite's two existing error tables (invented columns, no real data). */
beforeEach(function (): void {
    Schema::create('app_server_errors', function (Blueprint $table): void {
        $table->id();
        $table->string('severity');
        $table->string('exception_class')->nullable();
        $table->text('message');
        $table->text('trace')->nullable();
        $table->string('route_name')->nullable();
        $table->string('http_method')->nullable();
        $table->integer('http_status')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    Schema::create('app_browser_errors', function (Blueprint $table): void {
        $table->id();
        $table->text('message');
        $table->text('stack')->nullable();
        $table->text('page_url');
        $table->text('user_agent')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    config()->set('dennieos-signals.providers', [
        [
            'name' => 'server_errors',
            'table' => 'app_server_errors',
            'cursor_column' => 'id',
            'map' => [
                'occurred_at' => 'created_at', 'level' => 'severity', 'class' => 'exception_class', 'message' => 'message',
                'stack' => 'trace', 'route' => 'route_name', 'method' => 'http_method', 'status' => 'http_status', 'user_id' => 'user_id',
            ],
            'defaults' => ['class' => 'ServerError'],
        ],
        [
            'name' => 'browser_errors',
            'table' => 'app_browser_errors',
            'cursor_column' => 'id',
            'map' => ['occurred_at' => 'created_at', 'message' => 'message', 'stack' => 'stack', 'url' => 'page_url', 'user_agent' => 'user_agent', 'user_id' => 'user_id'],
            'defaults' => ['level' => 'error', 'class' => 'BrowserError'],
        ],
    ]);
    app()->forgetInstance(ProviderSync::class);
});

it('merges the existing server and browser error tables under the one cursor, scrubbed and mapped', function (): void {
    DB::table('app_server_errors')->insert([
        ['severity' => 'error', 'exception_class' => 'PDOException', 'message' => 'Duplicate entry for marieke@voorbeeld.nl', 'trace' => "#0 /srv/app/Models/User.php(10): save()", 'route_name' => 'users.store', 'http_method' => 'POST', 'http_status' => 500, 'user_id' => 7, 'created_at' => '2026-10-23 08:00:00'],
        ['severity' => 'info', 'exception_class' => null, 'message' => 'not an error', 'trace' => null, 'route_name' => null, 'http_method' => null, 'http_status' => null, 'user_id' => null, 'created_at' => '2026-10-23 08:00:01'],
    ]);
    DB::table('app_browser_errors')->insert([
        ['message' => "TypeError: Cannot read properties of undefined (reading 'map')", 'stack' => 'at render (app.js:10:5)', 'page_url' => 'https://tmw.example/projects/123/edit?token=abc#top', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15', 'user_id' => 7, 'created_at' => '2026-10-23 09:00:00'],
    ]);

    Artisan::call('dennieos:sync-providers');

    $rows = Database::table('dennieos_error_events')->orderBy('id')->get();
    $server = json_decode($rows[0]->context, true);
    $browser = json_decode($rows[1]->context, true);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->event_id)->toBe('server_errors:1')
        ->and($rows[0]->class)->toBe('PDOException')
        ->and($rows[0]->message)->toBe('Duplicate entry for [personal]')
        ->and($rows[0]->occurred_at)->toStartWith('2026-10-23 08:00:00')
        ->and($server)->toMatchArray(['route' => 'users.store', 'method' => 'POST', 'status' => 500, 'user_hash' => hash_hmac('sha256', '7', 'test-salt')])
        ->and($rows[1]->event_id)->toBe('browser_errors:1')
        ->and($rows[1]->class)->toBe('BrowserError')
        ->and($browser)->toMatchArray(['browser' => 'Safari 17', 'url_path' => '/projects/:id/edit'])
        ->and(json_encode($browser))->not->toContain('token')
        ->and($browser['user_hash'])->toBe($server['user_hash']);
});

it('reads only new rows on the next run, so nothing is imported twice', function (): void {
    DB::table('app_server_errors')->insert(['severity' => 'error', 'message' => 'one', 'created_at' => '2026-10-23 08:00:00']);
    Artisan::call('dennieos:sync-providers');
    Artisan::call('dennieos:sync-providers');
    expect(Database::table('dennieos_error_events')->count())->toBe(1);

    DB::table('app_server_errors')->insert(['severity' => 'critical', 'message' => 'two', 'created_at' => '2026-10-23 08:01:00']);
    Artisan::call('dennieos:sync-providers');

    expect(storedMessages())->toBe(['one', 'two']);
});

it('moves the cursor past rows below warning', function (): void {
    DB::table('app_server_errors')->insert([
        ['severity' => 'debug', 'message' => 'noise', 'created_at' => '2026-10-23 08:00:00'],
        ['severity' => 'notice', 'message' => 'more noise', 'created_at' => '2026-10-23 08:00:01'],
    ]);

    Artisan::call('dennieos:sync-providers');

    expect(Database::table('dennieos_error_events')->count())->toBe(0)
        ->and(\Dennieos\LaravelSignals\Storage\State::get('provider_cursor:server_errors'))->toBe('2');
});

it('drains a table larger than one batch in a single run', function (): void {
    DB::table('app_browser_errors')->insert(array_map(
        fn (int $i): array => ['message' => "error {$i}", 'page_url' => '/x', 'created_at' => '2026-10-23 08:00:00'],
        range(1, 1200),
    ));

    Artisan::call('dennieos:sync-providers');

    expect(Database::table('dennieos_error_events')->count())->toBe(1200);
});

it('accepts a custom provider class and survives a broken provider', function (): void {
    $custom = new class implements ErrorProvider {
        public function name(): string
        {
            return 'custom';
        }

        public function fetch(?string $afterCursor, int $limit): ProviderBatch
        {
            return $afterCursor === null
                ? new ProviderBatch([['id' => 'custom:1', 'occurred_at' => '2026-10-23T08:00:00Z', 'level' => 'error', 'message' => 'from custom']], '1', 1)
                : new ProviderBatch([], $afterCursor, 0);
        }
    };
    config()->set('dennieos-signals.providers', [['name' => 'broken', 'table' => 'does_not_exist', 'cursor_column' => 'id'], $custom]);
    app()->forgetInstance(ProviderSync::class);

    Artisan::call('dennieos:sync-providers');

    expect(storedMessages())->toBe(['from custom'])
        ->and((int) \Dennieos\LaravelSignals\Storage\State::get('dropped'))->toBe(1);
});

it('rejects a provider without a name, table or cursor column', function (): void {
    config()->set('dennieos-signals.providers', [['name' => 'x']]);
    app()->forgetInstance(ProviderSync::class);

    expect(fn () => app(ProviderSync::class))->toThrow(InvalidArgumentException::class);
});
