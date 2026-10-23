<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Console;

use Dennieos\LaravelSignals\Logging\LogScanner;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Support\LogFilter;
use Illuminate\Console\Command;

final class ScanLogsCommand extends Command
{
    protected $signature = 'dennieos:scan-logs';

    protected $description = 'Import complete entries of the included storage/logs files that did not reach the handler';

    public function handle(LogScanner $scanner): int
    {
        $stored = State::get(State::LOG_FILTER);
        $decoded = $stored === null ? null : json_decode($stored, true);
        $filter = (is_array($decoded) ? LogFilter::fromArray($decoded) : null) ?? LogFilter::default();

        $result = $scanner->scan($filter);
        $this->line(sprintf('Read %d file(s), imported %d event(s), %d file(s) failed.', $result['files_read'], $result['imported'], $result['failed']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
