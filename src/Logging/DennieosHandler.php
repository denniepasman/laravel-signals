<?php

declare(strict_types=1);

namespace Dennieos\LaravelSignals\Logging;

use Dennieos\LaravelSignals\Activation;
use Dennieos\LaravelSignals\Storage\EventStore;
use Dennieos\LaravelSignals\Support\EventBuilder;
use Dennieos\LaravelSignals\Support\LogEntryParser;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Stores every record at error level or above, scrubbed, in dennieos_error_events.
 *
 * The record is rendered the way Laravel renders it into laravel.log and then parsed by the same
 * parser as the log scanner, so the stable id of an entry is identical in both paths and an entry
 * that reaches the table here and later the log file is stored once. (An app with a custom log
 * formatter can produce two ids for one entry; switch the handler off then, or the scanner.)
 *
 * It never throws: an error handler that fails would hide the original error.
 */
final class DennieosHandler extends AbstractProcessingHandler
{
    private static bool $isWriting = false;

    public function __construct(
        private readonly EventStore $store,
        private readonly EventBuilder $builder,
        private readonly string $timezone,
        Level|int|string $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /** Decided per record: the handler is always attached, but stays silent without the secret. */
    public function isHandling(LogRecord $record): bool
    {
        return Activation::isActive() && parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        if (self::$isWriting) {
            return;
        }
        self::$isWriting = true;
        try {
            $formatter = new LineFormatter(null, 'Y-m-d H:i:s', true, true, true);
            $formatter->includeStacktraces();
            $parsed = LogEntryParser::parse(rtrim($formatter->format($record)), $this->timezone);
            if ($parsed === null) {
                return;
            }
            $exception = $record->context['exception'] ?? null;
            $request = RequestContext::capture($exception instanceof \Throwable ? $exception : null);
            $this->store->insertSafely($this->builder->build([
                ...$parsed,
                'context' => $request['context'],
                'user_id' => $request['user_id'],
            ]));
        } catch (\Throwable) {
            // Nothing to do: see the class comment.
        } finally {
            self::$isWriting = false;
        }
    }
}
