<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Console;

use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Activation;
use Illuminate\Console\Command;

final class HeartbeatCommand extends Command
{
    protected $signature = 'dennieos:heartbeat';

    protected $description = 'Record that the scheduler is running (reported to DennieOS in every response)';

    public function handle(): int
    {
        if (! Activation::isActive()) {
            $this->line(Activation::INACTIVE_MESSAGE);

            return self::SUCCESS;
        }

        State::touch(State::SCHEDULER_LAST_RUN_AT);

        return self::SUCCESS;
    }
}
