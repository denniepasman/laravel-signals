<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Support\LogFilter;

function encodedVectorFilter(): string
{
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../fixtures/client-intake-test-vectors.json'), true, 512, JSON_THROW_ON_ERROR);

    return $vectors['log_filter_encoding']['encoded'];
}

it('decodes the shared log_filter vector', function (): void {
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../fixtures/client-intake-test-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    $filter = LogFilter::decode(encodedVectorFilter());

    expect($filter)->not->toBeNull()
        ->and($filter->toArray())->toEqual($vectors['log_filter_encoding']['decoded']);
});

it('applies the rules to the file set of TestMyWebsite', function (): void {
    $filter = LogFilter::decode(encodedVectorFilter());
    $files = [
        'laravel-2026-10-23.log' => 'warning',
        'queue-worker.log' => null,
        'reverb.log' => null,
        'worker-tenant-21-a.log' => 'error',
        'deploy-2026-10-23.log' => null,
        'supervisor/queue.log' => null,
    ];

    foreach ($files as $path => $expected) {
        expect($filter->minimumLevelFor($path))->toBe($expected, $path);
    }
});

it('uses the default rules without a filter: both channels from warning, deploy logs off, others opt-in', function (): void {
    $filter = LogFilter::default();

    expect($filter->minimumLevelFor('laravel.log'))->toBe('warning')
        ->and($filter->minimumLevelFor('laravel-2026-10-23.log'))->toBe('warning')
        ->and($filter->minimumLevelFor('deploy-2026-10-23.log'))->toBeNull()
        ->and($filter->minimumLevelFor('queue-worker.log'))->toBeNull()
        ->and($filter->minimumLevelFor('supervisor/laravel.log'))->toBeNull();
});

it('lets an exclude win and the lowest minimum level of several includes apply', function (): void {
    $filter = LogFilter::fromArray(['rules' => [
        ['pattern' => '*.log', 'mode' => 'include', 'min_level' => 'critical'],
        ['pattern' => 'app-*.log', 'mode' => 'include', 'min_level' => 'error'],
        ['pattern' => 'app-old.log', 'mode' => 'exclude'],
    ]]);

    expect($filter->minimumLevelFor('app-1.log'))->toBe('error')
        ->and($filter->minimumLevelFor('other.log'))->toBe('critical')
        ->and($filter->minimumLevelFor('app-old.log'))->toBeNull()
        ->and($filter->minimumLevelFor('sub/other.log'))->toBeNull();
});

it('rejects an invalid filter', function (string $encoded): void {
    expect(LogFilter::decode($encoded))->toBeNull();
})->with([
    'not base64url' => ['not base64!'],
    'not json' => [rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=')],
    'no rules' => [rtrim(strtr(base64_encode('{"rules":"x"}'), '+/', '-_'), '=')],
    'bad mode' => [rtrim(strtr(base64_encode('{"rules":[{"pattern":"a","mode":"maybe"}]}'), '+/', '-_'), '=')],
    'bad level' => [rtrim(strtr(base64_encode('{"rules":[{"pattern":"a","mode":"include","min_level":"info"}]}'), '+/', '-_'), '=')],
]);
