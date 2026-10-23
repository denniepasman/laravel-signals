<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Support\Scrubber;

// Ported from apps/web/src/domain/signals/scrub.test.ts of DennieOS: the same cases, the same results.

const P = '[personal]';
const R = '[redacted]';

function message(string $text): string
{
    return Scrubber::text($text, Scrubber::MAX_MESSAGE_LENGTH);
}

it('replaces personal data', function (string $input, string $expected): void {
    expect(message($input))->toBe($expected);
})->with([
    'e-mail address' => ['mail marieke@voorbeeld.nl now', 'mail '.P.' now'],
    'IPv4' => ['from 203.0.113.42 ok', 'from '.P.' ok'],
    'IPv6 compressed' => ['from 2001:db8::1 ok', 'from '.P.' ok'],
    'IPv6 leading ::' => ['from ::1 ok', 'from '.P.' ok'],
    'IPv6 full' => ['from 2001:0db8:85a3:0000:0000:8a2e:0370:7334 ok', 'from '.P.' ok'],
    'IBAN' => ['iban NL91ABNA0417164300 end', 'iban '.P.' end'],
    'IBAN with spaces' => ['iban NL91 ABNA 0417 1643 00 end', 'iban '.P.' end'],
    'BSN' => ['bsn 111222333 end', 'bsn '.P.' end'],
    'Dutch mobile' => ['call 06-12345678 now', 'call '.P.' now'],
    'Dutch landline' => ['call (020) 123 4567 now', 'call '.P.' now'],
    'international phone' => ['call +31 6 12345678 now', 'call '.P.' now'],
    'international phone 00' => ['call 0044 20 7946 0958 now', 'call '.P.' now'],
]);

it('replaces credentials', function (string $input, string $expected): void {
    expect(message($input))->toBe($expected);
})->with([
    'query values' => ['GET /x?token=abc&page=2&q=hello', 'GET /x?token='.R.'&page='.R.'&q='.R],
    'cookie header' => ["Cookie: a=b; c=d\nnext", 'Cookie: '.R."\nnext"],
    'cookie assignment' => ['set-cookie = a=b', 'set-cookie: '.R],
    'quoted header' => ['{"Authorization": "Bearer abc", "x": 1}', '{"Authorization": "'.R.'", "x": 1}'],
    'single-quoted header' => ["'x-api-key': 'k'", "'x-api-key': '".R."'"],
    'bearer token' => ['got Bearer abc.def-ghi now', 'got Bearer '.R.' now'],
    'jwt' => ['t eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl z', 't '.R.' z'],
    'supabase key' => ['k sb_secret_N7UND0 z', 'k '.R.' z'],
    'password pair' => ['{"password":"hunter2"}', '{"password":"'.R.'"}'],
    'hex secret' => ['k 0123456789abcdef0123456789abcdef0123456789abcdef z', 'k '.R.' z'],
    'base64 secret' => ['k Zm9vQmFyMTIzNDU2Nzg5MEFiQ2RFZkdoSWpLbE1uT3A z', 'k '.R.' z'],
]);

it('keeps what is no personal data or credential', function (string $input): void {
    expect(message($input))->toBe($input);
})->with([
    'version numbers' => ['Laravel 11.9.2 on PHP 8.3.4 with next 15.1.3'],
    'four-part version' => ['build v1.2.3.4 and version 10.0.19045.1 and ver 2.3.4.5'],
    'line and column' => ['at src/app/page.tsx:42:11 and Connection.php(778)'],
    'uuid as id' => ['task 01234567-8901-2345-6789-012345678901 failed'],
    'git sha' => ['commit 4b96bd18b3c1d5e6f708192a3b4c5d6e7f809102'],
    'timestamp' => ['at 2026-10-23T12:34:56.789Z and 2026-10-23 12:34:56'],
    'date with time' => ['on 01-02-2026 10:00 it failed'],
    'dotted digits' => ['ref 0.10.100.1234 ok'],
    'epoch' => ['ts 1761220000000 ok'],
    'invalid iban' => ['code NL12ABNA0417164300 ok'],
    'too short for an iban' => ['code NL12ABCDEFGH ok'],
    'invalid bsn' => ['id 123456789 ok'],
    'short phone-like' => ['code +12 34 ok and 0612'],
    'php static call' => ['App\\Models\\User::find() and self::boot'],
    'long kebab identifier' => ['component my-very-long-kebab-case-component-name-with-more-words ok'],
    'long file path' => ['in /var/www/app/vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap2/HandleExceptions.php'],
    'time of day' => ['at 12:34:56 ok'],
    'plain words' => ["Cannot read properties of undefined (reading 'map')"],
]);

it('scrubs a Laravel stack trace', function (): void {
    $trace = <<<'TRACE'
Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'marieke@voorbeeld.nl' for key 'users_email_unique' (Connection: mysql, SQL: insert into `users` (`email`, `ip`) values (marieke@voorbeeld.nl, 203.0.113.42)) in /var/www/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:822
Stack trace:
#0 /var/www/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(778): Illuminate\Database\Connection->runQueryCallback()
#1 /var/www/app/app/Http/Controllers/SignupController.php:41
#2 {main}
Request: POST https://hollandia.example/signup?token=abc123def&page=2
Cookie: laravel_session=eyJpdiI6IkFCQ0QifQ%3D%3D; XSRF-TOKEN=abc
Authorization: Bearer 9f8e7d6c5b4a
Laravel 11.9.2, PHP 8.3.4, commit 4b96bd18b3c1d5e6f708192a3b4c5d6e7f809102
TRACE;
    $result = Scrubber::text($trace, Scrubber::MAX_STACK_LENGTH);

    expect($result)->not->toMatch('~marieke|203\.0\.113|abc123def|laravel_session|9f8e7d6c~')
        ->and($result)->toContain('Connection.php:822')
        ->and($result)->toContain('Laravel 11.9.2, PHP 8.3.4')
        ->and($result)->toContain('4b96bd18b3c1d5e6f708192a3b4c5d6e7f809102')
        ->and($result)->toContain('Cookie: [redacted]');
});

it('cuts to the maximum length with a marker and does not grow a truncated text', function (): void {
    $once = Scrubber::text(str_repeat('word ', 500), 100);

    expect(mb_strlen($once))->toBe(100)
        ->and($once)->toEndWith(Scrubber::TRUNCATED_MARKER)
        ->and(Scrubber::text($once, 100))->toBe($once)
        ->and(Scrubber::text('abcdefghijklmnopqrstuvwxyz', 3))->toBe(Scrubber::TRUNCATED_MARKER);
});

it('is idempotent over combinations of fragments', function (): void {
    $fragments = [
        'mail a@b.nl', '?token=x&y=1', 'Cookie: a=b', 'NL91ABNA0417164300', '111222333', '06-12345678',
        '10.0.0.1', '::1', 'v1.2.3.4', 'Bearer abc', '0123456789abcdef0123456789abcdef',
        'Zm9vQmFyMTIzNDU2Nzg5MEFiQ2RFZkdoSWpLbE1uT3A', str_repeat('x', 300), Scrubber::TRUNCATED_MARKER, '[red',
    ];
    $separators = [' ', "\n", '', ', ', ':'];
    mt_srand(7);
    for ($round = 0; $round < 300; $round++) {
        $text = '';
        for ($i = 0, $count = mt_rand(1, 5); $i < $count; $i++) {
            $text .= $fragments[mt_rand(0, count($fragments) - 1)].$separators[mt_rand(0, count($separators) - 1)];
        }
        $maxLength = [50, 300, 8000][mt_rand(0, 2)];
        $once = Scrubber::text($text, $maxLength);

        expect(Scrubber::text($once, $maxLength))->toBe($once);
    }
});

it('survives invalid UTF-8', function (): void {
    expect(Scrubber::text("bad \xC3\x28 bytes mail a@b.nl"))->toContain(P)->not->toContain('a@b.nl');
});

it('scrubs context: strings, sensitive and personal keys, metadata kept', function (): void {
    expect(Scrubber::context([
        'route' => '/invoices',
        'status' => 500,
        'ok' => false,
        'nothing' => null,
        'accessToken' => 'abc',
        'email' => 'a@b.nl',
        'userId' => '42',
        'user_hash' => 'abc123',
        'url' => 'https://x.example/p?token=abc',
        'nested' => ['ip' => '1.2.3.4', 'note' => 'mail a@b.nl'],
    ]))->toBe([
        'route' => '/invoices',
        'status' => 500,
        'ok' => false,
        'nothing' => null,
        'accessToken' => R,
        'email' => P,
        'userId' => P,
        'user_hash' => 'abc123',
        'url' => 'https://x.example/p?token='.R,
        'nested' => ['ip' => P, 'note' => 'mail '.P],
    ]);
});

it('drops context keys outside the allowed list and truncates deep structures', function (): void {
    expect(Scrubber::context(['route' => '/a', 'surprise' => 'x'], ['route']))->toBe(['route' => '/a'])
        ->and(Scrubber::context(['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]))
        ->toBe(['a' => ['b' => ['c' => ['d' => '[truncated]']]]]);
});

it('hashes a user id with the salt and refuses an empty salt', function (): void {
    $first = Scrubber::hashUserId('42', 'salt-a');

    expect($first)->toMatch('~^[0-9a-f]{64}$~')
        ->and(Scrubber::hashUserId('42', 'salt-a'))->toBe($first)
        ->and(Scrubber::hashUserId('42', 'salt-b'))->not->toBe($first)
        ->and(fn () => Scrubber::hashUserId('42', ''))->toThrow(InvalidArgumentException::class);
});
