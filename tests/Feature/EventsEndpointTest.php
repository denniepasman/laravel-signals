<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Support\Signature;
use Dennieos\LaravelSignals\Tests\TestCase;
use Dennieos\LaravelSignals\Version;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

const EVENTS_PATH = '/dennieos/intake/v1/events';

beforeEach(function (): void {
    $this->vectors = json_decode((string) file_get_contents(__DIR__.'/../fixtures/client-intake-test-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1792742410));
});

it('answers the signed first pull of the shared vectors with 200, no-store and a heartbeat', function (): void {
    $response = signedGet($this, EVENTS_PATH.'?limit=500', $this->vectors['secrets']['current'], 1792742400);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('events', [])
        ->assertJsonPath('heartbeat.contract_version', '1')
        ->assertJsonPath('heartbeat.package_version', Version::PACKAGE)
        ->assertJsonPath('heartbeat.dropped', 0)
        ->assertJsonPath('heartbeat.buffered', 0);
    expect($response->getContent())->toContain('"events":[]');
});

it('accepts the previous secret during a rotation', function (): void {
    signedGet($this, EVENTS_PATH.'?limit=500', $this->vectors['secrets']['previous'], 1792742400)->assertOk();
});

it('refuses with 401, without saying why', function (string $case): void {
    $path = EVENTS_PATH.'?limit=500';
    $response = match ($case) {
        'no headers' => $this->get($path),
        'wrong secret' => signedGet($this, $path, str_repeat('b', 64)),
        'expired' => signedGet($this, $path, null, 1792742410 - 301),
        'future' => signedGet($this, $path, null, 1792742410 + 301),
        'tampered' => $this->withHeaders([
            'X-DennieOS-Timestamp' => '1792742410',
            'X-DennieOS-Signature' => Signature::sign(TestCase::SECRET, 1792742410, 'GET', $path),
        ])->get(EVENTS_PATH.'?limit=499'),
        'garbage' => $this->withHeaders(['X-DennieOS-Timestamp' => 'x', 'X-DennieOS-Signature' => 'y'])->get($path),
    };

    $response->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
})->with(['no headers', 'wrong secret', 'expired', 'future', 'tampered', 'garbage']);

it('answers an empty 404 when no secret is configured', function (): void {
    config()->set('dennieos-signals.secret', null);
    config()->set('dennieos-signals.previous_secret', null);

    signedGet($this, EVENTS_PATH.'?limit=500', '')->assertNotFound();
});

it('returns the contract event shape in ascending order and pages with next_cursor', function (): void {
    storeEvent('a1', 'first');
    storeEvent('a2', 'second');
    storeEvent('a3', 'third');

    $page = signedGet($this, EVENTS_PATH.'?limit=2')->assertOk();
    $events = $page->json('events');

    expect(array_column($events, 'message'))->toBe(['first', 'second'])
        ->and($events[0])->toHaveKeys(['id', 'occurred_at', 'level', 'class', 'message', 'stack', 'fingerprint_hint', 'context'])
        ->and($events[0]['id'])->toBe('a1')
        ->and($events[0]['occurred_at'])->toBe('2026-10-23T08:14:03.512Z')
        ->and($events[0]['context'])->toBe([
            'route' => 'payments.store', 'method' => 'POST', 'status' => 500, 'release' => null,
            'user_hash' => null, 'browser' => null, 'url_path' => '/betalen/:id',
        ])
        ->and($page->json('next_cursor'))->toBe('2');

    $next = signedGet($this, EVENTS_PATH.'?cursor=2&limit=2');
    expect(array_column($next->json('events'), 'message'))->toBe(['third'])
        ->and($next->json('next_cursor'))->toBe('3');

    // Nothing new: the cursor is repeated.
    expect(signedGet($this, EVENTS_PATH.'?cursor=3&limit=2')->json('next_cursor'))->toBe('3');
});

it('answers an unknown cursor with 400 and a pruned cursor with 410', function (): void {
    storeEvent('a1');
    storeEvent('a2');

    signedGet($this, EVENTS_PATH.'?cursor=99')->assertStatus(400)->assertJsonPath('error', 'cursor_unknown');
    signedGet($this, EVENTS_PATH.'?cursor=01JB2Y8K7Q3W&limit=500')->assertStatus(400);

    Database::table('dennieos_error_events')->update(['created_at' => Carbon::now()->subDays(40)]);
    storeEvent('a3');
    expect(app(EventStore::class)->prune(30))->toBe(2);

    signedGet($this, EVENTS_PATH.'?cursor=1')->assertStatus(410);
    signedGet($this, EVENTS_PATH.'?cursor=2')->assertOk()->assertJsonPath('events.0.id', 'a3');
});

it('validates the limit', function (string $limit): void {
    signedGet($this, EVENTS_PATH.'?limit='.$limit)->assertStatus(400)->assertJsonPath('error', 'limit_invalid');
})->with(['0', '501', '-1', 'abc']);

it('stores the signed log_filter and uses it from the next scan', function (): void {
    $encoded = $this->vectors['log_filter_encoding']['encoded'];

    signedGet($this, EVENTS_PATH.'?cursor=0&limit=500&log_filter='.$encoded)->assertOk();

    expect(json_decode((string) State::get(State::LOG_FILTER), true))->toEqual($this->vectors['log_filter_encoding']['decoded']);
});

it('refuses an invalid log_filter and keeps the previous one', function (): void {
    State::put(State::LOG_FILTER, '{"rules":[]}');

    signedGet($this, EVENTS_PATH.'?log_filter=bm9wZQ')->assertStatus(400)->assertJsonPath('error', 'log_filter_invalid');

    expect(State::get(State::LOG_FILTER))->toBe('{"rules":[]}');
});

it('reports log files, channel and heartbeat times', function (): void {
    writeLog($this->logDir(), 'laravel-2026-10-23.log', "x\n", 1792742000);
    writeLog($this->logDir(), 'deploy-2026-10-22.log', "y\n", 1792700000);
    State::touch(State::SCHEDULER_LAST_RUN_AT);

    $heartbeat = signedGet($this, EVENTS_PATH)->assertOk()->json('heartbeat');

    expect($heartbeat['log_channel'])->toBe('daily')
        ->and($heartbeat['log_files'])->toBe([
            ['path' => 'laravel-2026-10-23.log', 'size_bytes' => 2, 'modified_at' => '2026-10-23T07:53:20Z'],
            ['path' => 'deploy-2026-10-22.log', 'size_bytes' => 2, 'modified_at' => '2026-10-22T20:13:20Z'],
        ])
        ->and($heartbeat['scheduler_last_run_at'])->toBe('2026-10-23T08:00:10Z')
        ->and($heartbeat['writer_last_ok_at'])->toBeNull();
});

it('detects single and mixed log channels', function (): void {
    writeLog($this->logDir(), 'laravel.log', "x\n");
    expect(signedGet($this, EVENTS_PATH)->json('heartbeat.log_channel'))->toBe('single');

    writeLog($this->logDir(), 'laravel-2026-10-23.log', "x\n");
    expect(signedGet($this, EVENTS_PATH)->json('heartbeat.log_channel'))->toBe('mixed');
});

it('counts events it could not store in the dropped counter, which the heartbeat reports', function (): void {
    Schema::drop('dennieos_error_events');

    app(EventStore::class)->insertSafely(app(\Dennieos\LaravelSignals\Support\EventBuilder::class)->build([
        'id' => 'x', 'occurred_at' => 'now', 'level' => 'error', 'message' => 'lost',
    ]));

    expect((int) State::get(State::DROPPED))->toBe(1);
});

it('keeps a response under the size limit by returning fewer events', function (): void {
    foreach (range(1, 3) as $i) {
        storeEvent("big{$i}", 'm');
        Database::table('dennieos_error_events')->where('event_id', "big{$i}")->update(['stack' => str_repeat('x', 2_600_000)]);
    }

    $response = signedGet($this, EVENTS_PATH.'?limit=500')->assertOk();

    expect(count($response->json('events')))->toBe(1)->and($response->json('next_cursor'))->toBe('1');
    expect(count(signedGet($this, EVENTS_PATH.'?cursor=1')->json('events')))->toBe(1);
});

it('is registered and active when the secret is set', function (): void {
    expect(\Illuminate\Support\Facades\Route::has('dennieos.intake.events'))->toBeTrue();
});
