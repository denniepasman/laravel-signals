<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Support;

/** One scrubbed contract event, ready to be stored. */
final class Event
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $id,
        public readonly string $occurredAt,
        public readonly string $level,
        public readonly string $class,
        public readonly string $message,
        public readonly ?string $stack,
        public readonly ?string $fingerprintHint,
        public readonly array $context,
    ) {
    }
}
