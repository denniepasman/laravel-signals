<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Contracts;

use Dennieos\LaravelSignals\Providers\ProviderBatch;

/**
 * A source of errors the app already stores itself (its own error tables). `dennieos:sync-providers`
 * reads new rows from every provider, scrubs them and stores them in dennieos_error_events, so all
 * providers, the Monolog handler and the log scanner end up under the one contract cursor.
 */
interface ErrorProvider
{
    /** Unique and stable: it is part of the event ids and of the provider's own cursor. */
    public function name(): string;

    /** Rows after $afterCursor (null = from the start), oldest first, at most $limit. */
    public function fetch(?string $afterCursor, int $limit): ProviderBatch;
}
