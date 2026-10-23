<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Providers;

use Dennieos\LaravelSignals\Contracts\ErrorProvider;
use Dennieos\LaravelSignals\Storage\Database;
use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Storage\State;
use Dennieos\LaravelSignals\Support\EventBuilder;

/** Copies new rows of every provider into the event table, one cursor per provider. */
final class ProviderSync
{
    private const BATCH = 500;
    private const MAX_BATCHES_PER_RUN = 40;

    /** @param list<ErrorProvider> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly EventStore $store,
        private readonly EventBuilder $builder,
    ) {
    }

    /** @return array<string, int> imported events per provider name */
    public function sync(): array
    {
        $imported = [];
        foreach ($this->providers as $provider) {
            $imported[$provider->name()] = 0;
            $key = 'provider_cursor:'.$provider->name();
            try {
                for ($batchNumber = 0; $batchNumber < self::MAX_BATCHES_PER_RUN; $batchNumber++) {
                    $cursor = State::get($key);
                    $batch = $provider->fetch($cursor, self::BATCH);
                    if ($batch->rowsRead === 0) {
                        break;
                    }
                    // Events and cursor move together: a failed insert retries the same rows next run.
                    Database::connection()->transaction(function () use ($batch, $key, &$imported, $provider): void {
                        foreach ($batch->events as $raw) {
                            if ($this->store->insert($this->builder->build($raw))) {
                                $imported[$provider->name()]++;
                            }
                        }
                        if ($batch->nextCursor !== null) {
                            State::put($key, $batch->nextCursor);
                        }
                    });
                    if ($batch->rowsRead < self::BATCH) {
                        break;
                    }
                }
            } catch (\Throwable) {
                // One broken provider (a renamed column, a missing table) must not stop the others.
                State::increment(State::DROPPED);
            }
        }

        return $imported;
    }
}
