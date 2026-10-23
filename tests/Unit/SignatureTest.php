<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Support\Signature;

/** The shared vectors of DennieOS (docs/integrations/client-intake-test-vectors.json). */
function vectors(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/client-intake-test-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
}

it('signs every vector exactly like DennieOS', function (array $request): void {
    $secret = vectors()['secrets'][$request['secret']];

    expect(Signature::sign($secret, $request['timestamp'], $request['method'], $request['path_and_query']))
        ->toBe($request['expected_signature']);
})->with(fn (): array => array_map(fn (array $r): array => [$r], vectors()['signed_requests']));

it('verifies the shared verification cases', function (array $case): void {
    $vectors = vectors();
    $request = collect($vectors['signed_requests'])->firstWhere('name', $case['request']);
    $secrets = [$vectors['secrets']['current'], $vectors['secrets']['previous']];

    $result = Signature::verify(
        $secrets,
        $case['timestamp_header'],
        $request['expected_signature'],
        $case['method'] ?? $request['method'],
        $case['path_and_query'] ?? $request['path_and_query'],
        $case['now'],
    );

    expect($result)->toBe($case['expected']);
})->with(fn (): array => array_map(fn (array $c): array => [$c], vectors()['verification_cases']));

it('refuses a malformed or missing header', function (?string $timestamp, ?string $signature): void {
    expect(Signature::verify(['s'], $timestamp, $signature, 'GET', '/x', 1792742400))->toBe(Signature::MALFORMED);
})->with([
    'no timestamp' => [null, 'sha256='.str_repeat('a', 64)],
    'non numeric timestamp' => ['abc', 'sha256='.str_repeat('a', 64)],
    'no signature' => ['1792742400', null],
    'short signature' => ['1792742400', 'sha256=abcd'],
    'uppercase hex' => ['1792742400', 'sha256='.str_repeat('A', 64)],
    'wrong scheme' => ['1792742400', 'sha1='.str_repeat('a', 64)],
]);

it('never accepts an empty secret', function (): void {
    $signature = Signature::sign('', 1792742400, 'GET', '/x');

    expect(Signature::verify([''], '1792742400', $signature, 'GET', '/x', 1792742400))->toBe(Signature::INVALID);
});
