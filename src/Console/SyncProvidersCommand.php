<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Console;

use Dennieos\LaravelSignals\Providers\ProviderSync;
use Dennieos\LaravelSignals\Activation;
use Illuminate\Console\Command;

final class SyncProvidersCommand extends Command
{
    protected $signature = 'dennieos:sync-providers';

    protected $description = 'Copy new rows from the configured table providers into dennieos_error_events';

    public function handle(ProviderSync $sync): int
    {
        if (! Activation::isActive()) {
            $this->line(Activation::INACTIVE_MESSAGE);

            return self::SUCCESS;
        }

        foreach ($sync->sync() as $name => $count) {
            $this->line(sprintf('%s: %d new event(s)', $name, $count));
        }

        return self::SUCCESS;
    }
}
