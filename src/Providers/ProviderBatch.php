<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Providers;

final class ProviderBatch
{
    /**
     * @param  list<array<string, mixed>>  $events  raw events (see EventBuilder::build); rows below warning are left out
     * @param  string|null  $nextCursor  the cursor after the last row read, also when every row was left out
     */
    public function __construct(
        public readonly array $events,
        public readonly ?string $nextCursor,
        public readonly int $rowsRead,
    ) {
    }
}
