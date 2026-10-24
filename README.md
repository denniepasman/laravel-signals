# dennieos/laravel-signals

Client error intake for [DennieOS](https://github.com/denniepasman/dennie-os) in Laravel apps (contract v1).

The package stores scrubbed errors in **your own database** and serves them through one **signed, read-only route**. DennieOS pulls them on its own schedule (default three times a day). Nothing is pushed anywhere, no database credentials ever leave the app, and what you keep, and for how long, is your decision.

- Laravel 10, 11, 12 and 13 (Laravel 13 needs PHP 8.3 or higher), PHP 8.2 or higher
- Contract: `docs/integrations/client-intake-contract.md` in the DennieOS repository (the signing test vectors live there and are copied to `tests/fixtures/`)

## Inert unless configured

The package is **active only when `DENNIEOS_INTAKE_SECRET` is set** (non-empty). Without it nothing happens: no Monolog handler, no scheduled commands, no intake route (a request gets a normal 404) and nothing is written to the database. The artisan commands stay available; they print `inactive: DENNIEOS_INTAKE_SECRET is not set` and exit 0. So you can install the package in every environment and set the secret only where it should report.

## What it does

| Part | Command or class | What it does |
| --- | --- | --- |
| Monolog handler | `DENNIEOS_CAPTURE_SERVER_ERRORS` (default on, once the secret is set) | Stores every record at `error` or above, scrubbed, in `dennieos_error_events`. Sets `context.command` for errors in console commands. |
| Log scanner | `php artisan dennieos:scan-logs` | Reads complete entries from the **included** files in `storage/logs` that did not reach the handler (warnings, other processes, history). Works for the `single` and the `daily` channel and never imports an entry twice. |
| Table providers | `php artisan dennieos:sync-providers` | Copies new rows from tables where your app already stores its own errors into the same table, under the same cursor. |
| Heartbeat | `php artisan dennieos:heartbeat` | Written every minute by the scheduler; reported in every response so DennieOS notices a silent source. |
| Intake route | `GET /dennieos/intake/v1/events` | Signed (HMAC-SHA256), constant-time check over the raw path and query, 5 minute window, current and previous secret, 60 requests per minute. |
| Pruning | `php artisan dennieos:prune` | Deletes events older than `DENNIEOS_RETENTION_DAYS` (default 30). DennieOS does not manage your retention. |
| Status | `php artisan dennieos:status` | Shows whether the installation is complete. |

## Install

The package is public on GitHub and not (yet) on Packagist. Add the repository to the app's `composer.json`; no token is needed:

```bash
composer config repositories.dennieos-signals vcs https://github.com/denniepasman/laravel-signals
composer require dennieos/laravel-signals:^1.0
php artisan migrate
```

The service provider is discovered automatically. Then, in the `.env` of the environment that should report (the secret and the salt are shown **once** in the DennieOS connect wizard; they are never committed):

```dotenv
DENNIEOS_INTAKE_SECRET=<from the DennieOS wizard>
DENNIEOS_USER_HASH_SALT=<from the DennieOS wizard>
DENNIEOS_RELEASE=<optional: deployed release or commit>
```

**Staging: do not set the secret.** An environment without `DENNIEOS_INTAKE_SECRET` is completely inert, so there are no flags to switch off. The migration always creates the (empty) tables, which is harmless and means setting the secret later needs no extra step.

Make sure the Laravel scheduler runs (one cron entry, if it is not there yet):

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Check the result:

```bash
php artisan dennieos:status
php artisan config:cache   # if the app caches its config, do it again after changing the .env
```

The route must be reachable over **HTTPS** from the internet (DennieOS refuses `http://`), must not be cached by a CDN (the package sends `Cache-Control: no-store`), and must not be behind a login or a firewall that DennieOS (Vercel, no fixed addresses) cannot pass. The signature is the protection.

### Secret rotation

In DennieOS choose _Geheim roteren_: a new secret is shown once. Put the new one in `DENNIEOS_INTAKE_SECRET` and keep the old one in `DENNIEOS_INTAKE_SECRET_PREVIOUS` for 24 hours; both are accepted meanwhile. Remove the previous one afterwards.

## Configuration

Publish the config with `php artisan vendor:publish --tag=dennieos-signals-config`. Everything below can be set from `.env`. The three flags only matter once the secret is set; they default to `true` then and can still be set to `false` explicitly.

| Variable | Default | Meaning |
| --- | --- | --- |
| `DENNIEOS_INTAKE_SECRET` | none | The HMAC secret. **Without it the package is inactive** (see above). |
| `DENNIEOS_INTAKE_SECRET_PREVIOUS` | none | The previous secret during a rotation. |
| `DENNIEOS_USER_HASH_SALT` | none | Salt for `context.user_hash`. Without it no user is recorded at all. |
| `DENNIEOS_RELEASE` | none | Sent as `context.release`. |
| `DENNIEOS_CAPTURE_SERVER_ERRORS` | `true` | The Monolog handler. Set `false` where the app already stores its own server errors. |
| `DENNIEOS_RETENTION_DAYS` | `30` | How long events stay in your table. |
| `DENNIEOS_SCHEDULE` | `true` | Register the scheduled commands. Set `false` to schedule them yourself. |
| `DENNIEOS_ROUTE_ENABLED` | `true` | Register the intake route. |
| `DENNIEOS_DB_CONNECTION` | default | Database connection for the package tables. |

## Which log files are read

DennieOS decides per source which files in `storage/logs` are monitored and sends the rules in the signed `log_filter` query parameter. The package stores the latest filter and applies it from the next `dennieos:scan-logs` run (every 5 minutes). Until a filter arrives it uses the default: include `laravel.log` and `laravel-*.log` from `warning`, exclude `deploy-*.log`, every other file is opt-in.

A rule is `{ pattern, mode: include|exclude, min_level: warning|error|critical }`, with a glob relative to `storage/logs` (`*` does not cross a `/`, `**` does). A file is read when an include rule matches and no exclude rule matches (exclude wins); with several include rules the lowest minimum level applies.

The heartbeat lists the files found (`log_files`: name, size and modification time of at most 200 files, never content) and the detected channel (`log_channel`: `single`, `daily` or `mixed`), so DennieOS can show them with a checkbox.

### `single` and `daily` without duplicates

- A **cursor per file** in `dennieos_log_cursors`: inode, byte offset, a hash of the first line and a hash of the bytes just before the offset. A different inode or first line, a size below the offset, or changed bytes before the offset mean the file was rotated, truncated (`copytruncate`) or rewritten: that file restarts at 0.
- A **stable id per entry**: a hash of timestamp, level, message and first stack frame. It is unique in `dennieos_error_events` and is the event `id`, so a restart, a copy of a file or a switch between `single` and `daily` cannot import an entry twice, and DennieOS dedupes on it as well.
- **Only complete entries** (header up to the next header, stack trace included). The last entry of a file is read once a newer header follows, or when the file has been quiet for 5 minutes. A half-written line is never imported.
- A file that retention deleted loses its cursor; what was imported stays.

The handler renders a record the way Laravel renders it into `laravel.log` and uses the same parser, so an entry that reaches the table through the handler and later through the file has the same id. If the app uses a custom log formatter the two ids can differ; then turn off the handler (`DENNIEOS_CAPTURE_SERVER_ERRORS=false`) or exclude the file.

## Apps that already store their errors: table providers

TestMyWebsite (and any app) that already stores its own errors can map those tables instead of using the handler. Set `DENNIEOS_CAPTURE_SERVER_ERRORS=false` and configure `providers` in `config/dennieos-signals.php`:

```php
'providers' => [
    [
        'name' => 'server_errors',          // part of the event ids, keep it stable
        'table' => 'server_errors',
        'cursor_column' => 'id',            // a unique, increasing column
        'map' => [
            'occurred_at' => 'created_at',
            'level' => 'severity',          // warning, error, critical (and syslog names); lower rows are skipped
            'class' => 'exception_class',
            'message' => 'message',
            'stack' => 'trace',
            'route' => 'route_name',
            'method' => 'http_method',
            'status' => 'http_status',
            'user_id' => 'user_id',         // hashed with the salt, never stored readable
        ],
        'defaults' => ['class' => 'ServerError'],
    ],
    [
        'name' => 'browser_errors',
        'table' => 'client_errors',
        'cursor_column' => 'id',
        'map' => [
            'occurred_at' => 'created_at',
            'message' => 'message',
            'stack' => 'stack',
            'url' => 'page_url',            // becomes url_path: ids replaced, no query string
            'user_agent' => 'user_agent',   // becomes browser: family and major version only
            'user_id' => 'user_id',
        ],
        'defaults' => ['level' => 'error', 'class' => 'BrowserError'],
    ],
],
```

A `map` value is a column name or a closure `fn (object $row): mixed`. For more control, implement `Dennieos\LaravelSignals\Contracts\ErrorProvider` and list the class in `providers`. The providers run every minute, copy new rows into `dennieos_error_events` and remember their own cursor, so all sources reach DennieOS under the one contract cursor. Your original tables are only read, never changed, and keep their own retention.

## Scrubbing and privacy

Everything is scrubbed **before** it is stored, with the same rules DennieOS uses at intake (a port of `domain/signals/scrub.ts`): e-mail addresses, IPv4 and IPv6 addresses, IBAN, BSN, phone numbers, query-string values, cookie and authorization headers, bearer tokens, JWTs and long secrets; absolute paths are made relative to the project root. A user is stored only as `user_hash = HMAC-SHA256(salt, user id)`. Only the contract's context keys are sent. Messages are cut at 1,000 characters and stacks at 8,000.

Scrubbing is a safety net, not a licence to log secrets: do not put personal data in exception messages.

## The route

`GET {app}/dennieos/intake/v1/events?cursor=&limit=&log_filter=` with the headers `X-DennieOS-Timestamp`, `X-DennieOS-Signature` (`sha256=` + hex HMAC-SHA256 over `timestamp\nGET\npath_and_query`), and `X-DennieOS-Source`. Answers: `200` events plus heartbeat; `400` unknown cursor or invalid `limit` / `log_filter`; `401` for every signature problem (it never says which); `410` the cursor was pruned; `429` over 60 requests per minute. A response never exceeds 5 MB and carries `Cache-Control: no-store`.

The signature is checked against `REQUEST_URI` exactly as sent. Do not rewrite or reorder the query string in a proxy in front of the app.

## Troubleshooting

- `php artisan dennieos:status` shows what is missing.
- DennieOS says the source is _stale_: the scheduler is not running (`Scheduler last ran: never`) or `dropped` is growing (the table cannot be written: run the migrations, check the database).
- `401` from the first pull: wrong secret, a clock that is more than 5 minutes off (sync it with NTP), or a proxy that changes the query string.
- Entries missing from DennieOS: the file is not included in the source's log rules, the level is below the file's minimum, or the entry is the last one of a file that is still being written (it is read once a newer entry follows or after 5 quiet minutes).

## Development

```bash
composer install
vendor/bin/pest
```

`tests/fixtures/client-intake-test-vectors.json` is a verbatim copy of `docs/integrations/client-intake-test-vectors.json` in the DennieOS repository, the single source for the signing vectors. When the contract changes there, copy the file again.

CI runs the suite on PHP 8.2 to 8.5 against Laravel 10 to 13 (each Laravel line on the PHP versions it supports) (`.github/workflows/tests.yml`). Releases are tagged `vMAJOR.MINOR.PATCH`; `Dennieos\LaravelSignals\Version::PACKAGE` is reported in the heartbeat so DennieOS can show outdated installations, so bump it with the tag.
