<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Support\LogFilter;

beforeEach(function (): void {
    $this->dir = $this->logDir();
    app()->instance(\Dennieos\LaravelSignals\Support\EventBuilder::class, new \Dennieos\LaravelSignals\Support\EventBuilder('/var/www/app', 'salt'));
    app()->forgetInstance(\Dennieos\LaravelSignals\Logging\LogScanner::class);
});

it('imports a single file that keeps growing without ever importing an entry twice', function (): void {
    $path = writeLog($this->dir, 'laravel.log', logEntry('2026-10-20 09:00:00', 'error', 'first').logEntry('2026-10-21 09:00:00', 'error', 'second'));

    expect(scanLogs()['imported'])->toBe(2);
    expect(scanLogs()['imported'])->toBe(0);

    appendLog($path, logEntry('2026-10-22 09:00:00', 'error', 'third'));
    expect(scanLogs()['imported'])->toBe(1);
    expect(storedMessages())->toBe(['first', 'second', 'third']);
    expect(Database::table('dennieos_log_cursors')->value('offset'))->toBe((int) filesize($path));
});

it('imports only complete entries: the last one waits for a newer header or for the file to go quiet', function (): void {
    $now = time();
    $path = writeLog($this->dir, 'laravel.log', logEntry('2026-10-20 09:00:00', 'error', 'first').logEntry('2026-10-21 09:00:00', 'error', 'second'), $now);

    // Just written: the second entry may still be growing.
    expect(scanLogs(null, $now)['imported'])->toBe(1)
        ->and(storedMessages())->toBe(['first']);

    // A newer header proves the second entry is complete.
    appendLog($path, logEntry('2026-10-22 09:00:00', 'error', 'third'), $now);
    expect(scanLogs(null, $now)['imported'])->toBe(1)
        ->and(storedMessages())->toBe(['first', 'second']);

    // Five quiet minutes later the last entry is complete too.
    expect(scanLogs(null, $now + 301)['imported'])->toBe(1)
        ->and(storedMessages())->toBe(['first', 'second', 'third']);
});

it('never imports a partially written last entry', function (): void {
    $complete = logEntry('2026-10-20 09:00:00', 'error', 'done');
    $partial = '[2026-10-21 09:00:00] production.ERR';
    $path = writeLog($this->dir, 'laravel.log', $complete.$partial);

    // Quiet for an hour: the first entry is complete, the half header is not an entry at all.
    scanLogs();
    expect(storedMessages())->toBe(['done']);

    // The writer finishes the line and a newer entry follows.
    file_put_contents($path, $complete.logEntry('2026-10-21 09:00:00', 'error', 'finished').logEntry('2026-10-21 09:00:05', 'error', 'next'));
    scanLogs();
    expect(storedMessages())->toBe(['done', 'finished']);

    scanLogs(null, time() + 301);
    expect(storedMessages())->toBe(['done', 'finished', 'next']);
});

it('reads a stack trace as part of its entry', function (): void {
    writeLog($this->dir, 'laravel-2026-10-23.log', logEntry('2026-10-23 08:14:03', 'error', 'Gateway timeout', true).logEntry('2026-10-23 08:15:00', 'warning', 'Slow'));

    scanLogs();
    $row = Database::table('dennieos_error_events')->orderBy('id')->first();
    $context = json_decode($row->context, true);

    expect(Database::table('dennieos_error_events')->count())->toBe(2)
        ->and($row->class)->toBe('RuntimeException')
        ->and($row->message)->toBe('Gateway timeout')
        ->and($row->level)->toBe('error')
        ->and($row->stack)->toContain('#0 app/Http/Controllers/PaymentController.php(41)')
        ->and($row->stack)->toContain('#2 {main}')
        ->and($row->stack)->not->toContain('/var/www/app/app')
        ->and($context['log_file'])->toBe('laravel-2026-10-23.log')
        ->and($row->occurred_at)->toStartWith('2026-10-23 06:14:03'); // Europe/Amsterdam (CEST) to UTC
});

it('picks up a late line in yesterdays daily file', function (): void {
    $yesterday = writeLog($this->dir, 'laravel-2026-10-22.log', logEntry('2026-10-22 23:00:00', 'error', 'evening'));
    writeLog($this->dir, 'laravel-2026-10-23.log', logEntry('2026-10-23 08:00:00', 'error', 'morning'));
    scanLogs();

    appendLog($yesterday, logEntry('2026-10-22 23:59:59', 'error', 'late line'));
    scanLogs();

    expect(storedMessages())->toBe(['evening', 'morning', 'late line']);
});

it('restarts a truncated file (logrotate copytruncate) without importing anything twice', function (): void {
    $first = logEntry('2026-10-20 09:00:00', 'error', 'one').logEntry('2026-10-21 09:00:00', 'error', 'two').logEntry('2026-10-21 10:00:00', 'error', 'three');
    $path = writeLog($this->dir, 'laravel.log', $first);
    scanLogs();

    // copytruncate: the content is copied away and the same inode is emptied, then new lines arrive.
    file_put_contents($path, '');
    touch($path, time() - 3600);
    scanLogs();
    expect(Database::table('dennieos_log_cursors')->value('offset'))->toBe(0);

    file_put_contents($path, logEntry('2026-10-22 09:00:00', 'error', 'four'));
    touch($path, time() - 3600);
    scanLogs();

    expect(storedMessages())->toBe(['one', 'two', 'three', 'four']);
});

it('restarts at 0 when the file was truncated and rewritten with some of the same entries', function (): void {
    $path = writeLog($this->dir, 'laravel.log', logEntry('2026-10-20 09:00:00', 'error', 'one').logEntry('2026-10-21 09:00:00', 'error', 'two'));
    scanLogs();

    // Smaller than the old offset, first line is the same entry again plus a new one.
    file_put_contents($path, logEntry('2026-10-20 09:00:00', 'error', 'one').logEntry('2026-10-22 09:00:00', 'error', 'new'));
    touch($path, time() - 3600);
    scanLogs();

    expect(storedMessages())->toBe(['one', 'two', 'new']);
});

it('restarts a file that was replaced (a new inode) even when it grew', function (): void {
    $path = writeLog($this->dir, 'laravel.log', logEntry('2026-10-20 09:00:00', 'error', 'old one'));
    scanLogs();

    unlink($path);
    writeLog($this->dir, 'laravel.log', logEntry('2026-10-25 09:00:00', 'error', 'brand new entry with a longer text so the size exceeds the old offset').logEntry('2026-10-25 09:00:01', 'error', 'another'));
    scanLogs();

    expect(storedMessages())->toBe(['old one', 'brand new entry with a longer text so the size exceeds the old offset', 'another']);
});

it('drops the cursor of a file that retention deleted', function (): void {
    $path = writeLog($this->dir, 'laravel-2026-10-01.log', logEntry('2026-10-01 09:00:00', 'error', 'ancient'));
    writeLog($this->dir, 'laravel-2026-10-23.log', logEntry('2026-10-23 09:00:00', 'error', 'today'));
    scanLogs();
    expect(Database::table('dennieos_log_cursors')->count())->toBe(2);

    unlink($path);
    scanLogs();

    expect(Database::table('dennieos_log_cursors')->pluck('path')->all())->toBe(['laravel-2026-10-23.log'])
        ->and(storedMessages())->toBe(['ancient', 'today']); // what was imported stays
});

it('does not double entries when a project switches from single to daily and back', function (): void {
    $entries = logEntry('2026-10-20 09:00:00', 'error', 'one').logEntry('2026-10-21 09:00:00', 'error', 'two');
    $single = writeLog($this->dir, 'laravel.log', $entries);
    scanLogs();

    // Switch to daily: the daily file carries the recent entries again plus a new one; the old file is left.
    writeLog($this->dir, 'laravel-2026-10-21.log', logEntry('2026-10-21 09:00:00', 'error', 'two').logEntry('2026-10-21 12:00:00', 'error', 'three'));
    scanLogs();
    expect(storedMessages())->toBe(['one', 'two', 'three']);

    // And back to single: the single file keeps growing, with a copy of an entry already imported.
    appendLog($single, logEntry('2026-10-21 12:00:00', 'error', 'three').logEntry('2026-10-22 09:00:00', 'error', 'four'));
    scanLogs();
    expect(storedMessages())->toBe(['one', 'two', 'three', 'four']);
});

it('reads only included files and applies the minimum level per pattern', function (): void {
    $filter = LogFilter::decode('eyJydWxlcyI6W3sicGF0dGVybiI6ImxhcmF2ZWwtKi5sb2ciLCJtb2RlIjoiaW5jbHVkZSIsIm1pbl9sZXZlbCI6Indhcm5pbmcifSx7InBhdHRlcm4iOiJ3b3JrZXItKi5sb2ciLCJtb2RlIjoiaW5jbHVkZSIsIm1pbl9sZXZlbCI6ImVycm9yIn0seyJwYXR0ZXJuIjoiZGVwbG95LSoubG9nIiwibW9kZSI6ImV4Y2x1ZGUifV19');
    $mixed = fn (string $tag): string => logEntry('2026-10-23 08:00:00', 'info', "{$tag} info")
        .logEntry('2026-10-23 08:00:01', 'warning', "{$tag} warning")
        .logEntry('2026-10-23 08:00:02', 'error', "{$tag} error")
        .logEntry('2026-10-23 08:00:03', 'critical', "{$tag} critical");

    writeLog($this->dir, 'laravel-2026-10-23.log', $mixed('laravel'));
    writeLog($this->dir, 'queue-worker.log', $mixed('queue'));
    writeLog($this->dir, 'reverb.log', $mixed('reverb'));
    writeLog($this->dir, 'worker-tenant-21-a.log', $mixed('worker'));
    writeLog($this->dir, 'deploy-2026-10-23.log', $mixed('deploy'));
    writeLog($this->dir, 'supervisor/supervisord.log', $mixed('supervisor'));

    $result = scanLogs($filter);

    expect($result['files_read'])->toBe(2)
        ->and(storedMessages())->toBe(['laravel warning', 'laravel error', 'laravel critical', 'worker error', 'worker critical']);
});

it('uses the default rules when DennieOS has not sent a filter', function (): void {
    $entry = logEntry('2026-10-23 08:00:00', 'error', 'boom');
    writeLog($this->dir, 'laravel.log', $entry);
    writeLog($this->dir, 'laravel-2026-10-23.log', logEntry('2026-10-23 08:00:00', 'warning', 'daily warning'));
    writeLog($this->dir, 'deploy-2026-10-23.log', $entry);
    writeLog($this->dir, 'other.log', $entry);

    scanLogs();

    expect(storedMessages())->toEqualCanonicalizing(['boom', 'daily warning']);
});

it('reads a file larger than one chunk and an entry larger than the chunk', function (): void {
    config()->set('dennieos-signals.logs.chunk_bytes', 512);
    app()->forgetInstance(\Dennieos\LaravelSignals\Logging\LogScanner::class);
    $content = '';
    foreach (range(1, 30) as $i) {
        $content .= logEntry(sprintf('2026-10-20 09:00:%02d', $i), 'error', "entry {$i}");
    }
    $content .= logEntry('2026-10-21 09:00:00', 'error', str_repeat('very long message ', 200));
    $content .= logEntry('2026-10-21 09:00:01', 'error', 'after');
    writeLog($this->dir, 'laravel.log', $content);

    scanLogs();

    expect(Database::table('dennieos_error_events')->count())->toBe(32);
});

it('ignores lines before the first header', function (): void {
    writeLog($this->dir, 'laravel.log', "stray line without header\n".logEntry('2026-10-20 09:00:00', 'error', 'real'));

    scanLogs();

    expect(storedMessages())->toBe(['real']);
});

it('stores a message with scrubbed personal data and relative paths', function (): void {
    writeLog($this->dir, 'laravel.log', logEntry('2026-10-20 09:00:00', 'error', 'Mail failed for marieke@voorbeeld.nl from 203.0.113.42'));

    scanLogs();

    expect(storedMessages())->toBe(['Mail failed for [personal] from [personal]']);
});

it('gives the same stable id to entries that differ only in channel name or an empty context suffix', function (): void {
    $a = Dennieos\LaravelSignals\Support\LogEntryParser::parse("[2026-10-20 09:00:00] production.ERROR: boom\n", 'UTC');
    $b = Dennieos\LaravelSignals\Support\LogEntryParser::parse("[2026-10-20 09:00:00] local.ERROR: boom [] []\n", 'UTC');
    $c = Dennieos\LaravelSignals\Support\LogEntryParser::parse("[2026-10-20 09:00:01] production.ERROR: boom\n", 'UTC');

    expect($a['id'])->toBe($b['id'])->and($a['id'])->not->toBe($c['id'])->and(strlen($a['id']))->toBeLessThanOrEqual(64);
});
