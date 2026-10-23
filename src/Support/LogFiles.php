<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

/** The files under storage/logs (relative paths with `/`), for the scanner and the heartbeat. */
final class LogFiles
{
    private const MAX_DEPTH = 3;
    private const MAX_FILES = 2000;
    public const HEARTBEAT_LIMIT = 200;

    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return list<array{path: string, absolute: string, size: int, mtime: int, inode: ?int}> newest first */
    public function list(): array
    {
        $files = [];
        $this->walk($this->directory, '', 0, $files);
        usort($files, fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime'] ?: strcmp($a['path'], $b['path']));

        return $files;
    }

    /** `single`, `daily` or `mixed` from the file names present; null when neither is found. */
    public static function detectChannel(array $files): ?string
    {
        $hasSingle = false;
        $hasDaily = false;
        foreach ($files as $file) {
            $hasSingle = $hasSingle || $file['path'] === 'laravel.log';
            $hasDaily = $hasDaily || preg_match('~^laravel-\d{4}-\d{2}-\d{2}\.log$~', $file['path']) === 1;
        }

        return match (true) {
            $hasSingle && $hasDaily => 'mixed',
            $hasSingle => 'single',
            $hasDaily => 'daily',
            default => null,
        };
    }

    /** @param list<array{path: string, absolute: string, size: int, mtime: int, inode: ?int}> $files */
    private function walk(string $directory, string $prefix, int $depth, array &$files): void
    {
        if (! is_dir($directory) || count($files) >= self::MAX_FILES) {
            return;
        }
        $names = scandir($directory) ?: [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name === '.gitignore') {
                continue;
            }
            $absolute = $directory.DIRECTORY_SEPARATOR.$name;
            if (is_link($absolute)) {
                continue;
            }
            if (is_dir($absolute)) {
                if ($depth + 1 < self::MAX_DEPTH) {
                    $this->walk($absolute, $prefix.$name.'/', $depth + 1, $files);
                }
            } elseif (is_file($absolute) && strlen($prefix.$name) <= 255) {
                $inode = @fileinode($absolute);
                $files[] = [
                    'path' => $prefix.$name,
                    'absolute' => $absolute,
                    'size' => (int) @filesize($absolute),
                    'mtime' => (int) @filemtime($absolute),
                    'inode' => $inode === false || $inode === 0 ? null : $inode,
                ];
            }
        }
    }
}
