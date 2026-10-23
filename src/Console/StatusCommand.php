<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Console;

use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Support\LogFiles;
use Dennieos\LaravelSignals\Version;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class StatusCommand extends Command
{
    protected $signature = 'dennieos:status';

    protected $description = 'Show whether the DennieOS intake is installed correctly';

    public function handle(LogFiles $files): int
    {
        $tables = Schema::connection(config('dennieos-signals.connection'))->hasTable('dennieos_error_events');
        $this->line('Package version: '.Version::PACKAGE.' (contract v'.Version::CONTRACT.')');
        $this->line('Tables migrated: '.($tables ? 'yes' : 'NO, run php artisan migrate'));
        $this->line('Intake secret set: '.(config('dennieos-signals.secret') ? 'yes' : 'NO (DENNIEOS_INTAKE_SECRET)'));
        $this->line('User hash salt set: '.(config('dennieos-signals.user_hash_salt') ? 'yes' : 'no (no user is recorded)'));
        $this->line('Monolog handler: '.(config('dennieos-signals.capture_server_errors') ? 'on' : 'off'));
        if (! $tables) {
            return self::FAILURE;
        }
        $this->line('Stored events: '.Database::table('dennieos_error_events')->count());
        $this->line('Scheduler last ran: '.(State::get(State::SCHEDULER_LAST_RUN_AT) ?? 'never (is the scheduler running?)'));
        $this->line('Dropped events: '.(int) State::get(State::DROPPED));
        $this->line('Log channel: '.(LogFiles::detectChannel($files->list()) ?? 'none found'));

        return self::SUCCESS;
    }
}
