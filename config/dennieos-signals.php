<?php

declare(strict_types=1);

return [
    /*
     | The intake secret DennieOS signs its requests with (shown once in the DennieOS connect wizard).
     | During a rotation set the old one as DENNIEOS_INTAKE_SECRET_PREVIOUS; both work for 24 hours.
     */
    'secret' => env('DENNIEOS_INTAKE_SECRET'),
    'previous_secret' => env('DENNIEOS_INTAKE_SECRET_PREVIOUS'),

    /* Salt for the user hash (HMAC-SHA256 of the user id). Without it no user is recorded at all. */
    'user_hash_salt' => env('DENNIEOS_USER_HASH_SALT'),

    /* The deployed release or commit, sent as context.release. */
    'release' => env('DENNIEOS_RELEASE'),

    /* Database connection for the package tables; null = the default connection. */
    'connection' => env('DENNIEOS_DB_CONNECTION'),

    /* The Monolog handler. Switch off where the app already stores its own server errors. */
    'capture_server_errors' => (bool) env('DENNIEOS_CAPTURE_SERVER_ERRORS', true),

    /* The route group; the contract fixes /dennieos/intake/v1/events. */
    'route' => [
        'enabled' => (bool) env('DENNIEOS_ROUTE_ENABLED', true),
        'prefix' => 'dennieos/intake/v1',
        'middleware' => [],
        'rate_limit_per_minute' => 60,
    ],

    /* How long the client keeps events in dennieos_error_events. DennieOS does not manage this. */
    'retention_days' => (int) env('DENNIEOS_RETENTION_DAYS', 30),

    'logs' => [
        'path' => null, // null = storage_path('logs')
        /* An entry still being written is read only once the file has been quiet this long. */
        'quiet_seconds' => 300,
        /* Bytes read per file per run; bigger entries make the read grow up to max_entry_bytes. */
        'chunk_bytes' => 2 * 1024 * 1024,
        'max_entry_bytes' => 16 * 1024 * 1024,
    ],

    /* Register the scheduled commands (heartbeat every minute, scan, providers, prune). */
    'schedule' => (bool) env('DENNIEOS_SCHEDULE', true),

    /*
     | Table providers: apps that already store their own errors. Each entry is either an array
     | (a configured table) or a class-string / instance implementing
     | Dennieos\LaravelSignals\Contracts\ErrorProvider. All providers end up under one cursor.
     |
     | Array form:
     |   'name'           unique name, part of the event ids
     |   'table'          the app's table      'connection'  optional
     |   'cursor_column'  a unique, increasing column (an auto-increment id)
     |   'map'            contract field => column name, or fn (object $row): mixed
     |                    fields: occurred_at, level, class, message, stack, route, method, status,
     |                    release, user_id (hashed with the salt), user_agent (-> browser family),
     |                    url (-> url_path), url_path, command, fingerprint_hint, id
     |   'defaults'       field => constant value (e.g. ['level' => 'error', 'class' => 'BrowserError'])
     |   'where'          optional [column => value] filter
     */
    'providers' => [],
];
