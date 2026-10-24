<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Console;

use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Activation;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'dennieos:prune {--days= : Override DENNIEOS_RETENTION_DAYS}';

    protected $description = 'Delete events older than the retention you set (DENNIEOS_RETENTION_DAYS, default 30)';

    public function handle(EventStore $store): int
    {
        if (! Activation::isActive()) {
            $this->line(Activation::INACTIVE_MESSAGE);

            return self::SUCCESS;
        }

        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('dennieos-signals.retention_days', 30);
        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }
        $this->line(sprintf('Pruned %d event(s) older than %d day(s).', $store->prune($days), $days));

        return self::SUCCESS;
    }
}
