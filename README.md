<div align="center">
    <a href="https://schaefersoft.ch">
        <picture>
            <source media="(prefers-color-scheme: light)" srcset="https://schaefersoft.ch/_static/logos/full_logo/dark/logo_full_dark.svg">
            <img alt="SchaeferSoft" src="https://schaefersoft.ch/_static/logos/full_logo/light/logo_full_light.svg" width="360">
        </picture>
    </a>
</div>

# Laravel Seq

[![Tests](https://github.com/schaefersoft/laravel-seq/actions/workflows/tests.yml/badge.svg)](https://github.com/schaefersoft/laravel-seq/actions/workflows/tests.yml)
[![Total downloads](https://img.shields.io/packagist/dt/schaefersoft/laravel-seq)](https://packagist.org/packages/schaefersoft/laravel-seq)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/schaefersoft/laravel-seq)](https://packagist.org/packages/schaefersoft/laravel-seq)
[![License](https://img.shields.io/packagist/l/schaefersoft/laravel-seq)](LICENSE)

Structured logging from Laravel to [Seq](https://datalust.co/seq). Placeholders like `{order_id}` become properties you
can search, filter and chart on, and exceptions arrive with their full stack trace.

Events are buffered and shipped in batches after the response has been sent, so logging never slows down or breaks
your application.

## Features

- A `seq` log driver and a ready-to-use `seq` channel, registered automatically
- PSR-3 placeholders become Seq message templates, context becomes event properties
- Exceptions, including previous exceptions, land in Seq's exception view
- `Log::withContext()`, `Log::shareContext()` and Laravel's `Context` are shipped as properties
- Batched delivery after the response, between queue jobs and when a process ends, even after fatal errors
- Short timeouts, and delivery failures never reach your application
- A circuit breaker pauses shipping while Seq is unreachable, so requests and workers are not held up
- Oversized events are trimmed instead of failing the whole batch
- `php artisan seq:test` to verify the connection and `Seq::fake()` for your own tests

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- Seq 2023.4+

## Installation

```bash
composer require schaefersoft/laravel-seq
```

The service provider is discovered automatically.

## Quick start

Point the package to your Seq server. Without `SEQ_URL`, nothing is shipped:

```dotenv
SEQ_URL=https://seq.example.com
SEQ_API_KEY=your-api-key
```

Add the `seq` channel to your log stack:

```dotenv
LOG_STACK=daily,seq
```

Check that everything works:

```bash
php artisan seq:test
```

## Configuration

| Variable                    | Default                | Description                                                                          |
|-----------------------------|------------------------|--------------------------------------------------------------------------------------|
| `SEQ_URL`                   | –                      | Base URL of your Seq server, nothing is shipped without it                           |
| `SEQ_API_KEY`               | –                      | API key, sent as `X-Seq-ApiKey` header                                               |
| `SEQ_ENABLED`               | `true`                 | Set to `false` to stop shipping events                                               |
| `SEQ_LEVEL`                 | `LOG_LEVEL` or `debug` | Minimum level shipped to Seq                                                         |
| `SEQ_TIMEOUT`               | `2`                    | Request timeout in seconds                                                           |
| `SEQ_CONNECT_TIMEOUT`       | `1`                    | Connection timeout in seconds                                                        |
| `SEQ_BATCH_SIZE`            | `100`                  | Maximum number of events per request                                                 |
| `SEQ_FLUSH_INTERVAL`        | `5`                    | Maximum age in seconds of buffered events in long-running processes, `0` disables it |
| `SEQ_MAX_EVENT_SIZE`        | `262144`               | Maximum event size in bytes, matching Seq's default                                  |
| `SEQ_CIRCUIT_BREAKER`       | `30`                   | Seconds to pause shipping after Seq could not be reached, `0` disables it            |
| `SEQ_CIRCUIT_BREAKER_STORE` | –                      | Cache store for the circuit breaker state, see [Failures](#failures)                 |

Publish the configuration file to change the defaults:

```bash
php artisan vendor:publish --tag=seq-config
```

### Properties

Every event carries the `properties` from `config/seq.php`. By default these are `Application` and `Environment`, which
lets you tell applications apart on a shared Seq server:

```php
'properties' => [
    'Application' => env('APP_NAME', 'Laravel'),
    'Environment' => env('APP_ENV', 'production'),
    'Server' => gethostname(),
],
```

Properties with a `null` value are skipped. Context values with the same name take precedence.

### Channels

The registered `seq` channel reads everything from `config/seq.php`. Define channels with the `seq` driver in
`config/logging.php` to override any of those options per channel, for example to ship audit events to a second Seq
server:

```php
'channels' => [
    'audit' => [
        'driver' => 'seq',
        'url' => env('SEQ_AUDIT_URL'),
        'api_key' => env('SEQ_AUDIT_API_KEY'),
        'level' => 'info',
        'properties' => ['Application' => 'Billing'],
        'processors' => [
            Monolog\Processor\WebProcessor::class,
            ['processor' => Monolog\Processor\UidProcessor::class, 'with' => ['length' => 12]],
        ],
    ],
],
```

Besides the options of `config/seq.php`, channels accept `bubble`, `name`, `processors` and Laravel's `tap`. Processors
only apply to the events shipped to Seq, even when the channel is part of a stack. `properties` defined on a channel
replace the default properties.

## Usage

Keep the message constant and pass the values as context:

```php
Log::info('Order {order_id} placed by {email}', ['order_id' => 1042, 'email' => 'ada@example.com']);
```

Seq receives the event as [CLEF](https://clef-json.org):

```json
{"@t":"2026-09-28T10:34:56.123456Z","@mt":"Order {order_id} placed by {email}","@l":"Information","Application":"Shop","Environment":"production","order_id":1042,"email":"ada@example.com"}
```

Seq renders the message as `Order 1042 placed by ada@example.com`, lets you filter with `order_id = 1042` and groups all
events of the same template. Interpolating values yourself (`"Order {$id} placed"`) loses all of that.

### Exceptions

Exceptions passed in the `exception` context key are shipped to Seq's exception field, including previous exceptions
and stack traces. Laravel's exception handler does this for every reported exception, so they show up in Seq as soon as
the `seq` channel is part of your default stack.

```php
Log::error('Payment failed for order {order_id}', ['order_id' => 1042, 'exception' => $exception]);
```

### Context

Context from `Log::withContext()`, `Log::shareContext()` and Laravel's `Context` facade is shipped as properties:

```php
Context::add('request_id', (string) Str::uuid());
```

### Values

| Value                                                    | Shipped as                             |
|----------------------------------------------------------|----------------------------------------|
| Strings, numbers, booleans, arrays                       | As is                                  |
| Dates                                                    | ISO 8601 string                        |
| Enums                                                    | Value of backed enums, name otherwise  |
| `JsonSerializable` and `Arrayable`, like models          | Structured object with a `$type`       |
| `Stringable`                                             | String                                 |
| Other objects                                            | Public properties with a `$type`       |

Braces that are not placeholders are escaped, so `Blade {{ $name }} failed` is rendered exactly as written. Property
names starting with `@` are escaped as CLEF requires.

### Levels

| Laravel                        | Seq           |
|--------------------------------|---------------|
| `debug`                        | `Debug`       |
| `info`, `notice`               | `Information` |
| `warning`                      | `Warning`     |
| `error`                        | `Error`       |
| `critical`, `alert`, `emergency` | `Fatal`     |

## Delivery

Events are buffered in memory and shipped in batches:

| Situation                 | Events are shipped                                   |
|---------------------------|------------------------------------------------------|
| HTTP requests             | After the response has been sent                     |
| Artisan commands          | When the command has finished                        |
| Queue workers and Horizon | After every job and when the worker stops            |
| Octane                    | After every request                                  |
| Every process             | When the process ends, even after a fatal error      |

A batch is also shipped as soon as `SEQ_BATCH_SIZE` events are buffered, or when an event is logged while the oldest
buffered event is older than `SEQ_FLUSH_INTERVAL` seconds. This keeps long-running commands close to real time. In your
own long-running loops, ship the buffer explicitly:

```php
use SchaeferSoft\Seq\Facades\Seq;

Seq::flush();
```

### Failures

Requests use short timeouts. Delivery errors are swallowed and the affected events are dropped, so logging never throws
and never blocks longer than the timeouts. After a connection failure, the remaining batches of that flush are dropped
instead of waiting for more timeouts. Keep a local channel in your stack, like `LOG_STACK=daily,seq`, to not lose any
events, and run `php artisan seq:test` to find out what is wrong.

When Seq cannot be reached or does not answer in time, a circuit breaker pauses shipping for `SEQ_CIRCUIT_BREAKER`
seconds and drops the events of that time. Otherwise every request would wait for the timeouts, and under load the
PHP-FPM pool would fill up. Error responses from Seq do not trip the circuit breaker. Its state is kept per Seq server:

| Runtime                          | State is kept in                                   |
|----------------------------------|----------------------------------------------------|
| PHP-FPM with APCu                | APCu, shared by all workers of the pool            |
| Octane, queue workers, commands  | Memory of the process                              |
| `SEQ_CIRCUIT_BREAKER_STORE` set  | The Laravel cache store of that name, like `redis` |

Without APCu, PHP-FPM forgets the state after every request. Install APCu or set `SEQ_CIRCUIT_BREAKER_STORE` to a
cache store that responds quickly, since it is queried before every delivery.

### Large events

Seq rejects events larger than 256 KB and requests larger than 10 MB, and a single rejected event makes it reject the
whole request. To prevent that, requests are split into chunks of at most 1 MB, and oversized events are trimmed:

1. The largest properties are removed and listed in `DroppedProperties`.
2. If that is not enough, the message and exception messages are truncated.
3. As a last resort, the event is replaced by a placeholder with a sample of the original.

If you raised the event size limit of your Seq server, raise `SEQ_MAX_EVENT_SIZE` as well.

## Checking the connection

`seq:test` sends a test event with the settings of a channel and explains what went wrong if Seq rejects it:

```bash
php artisan seq:test
php artisan seq:test --channel=audit
```

`php artisan about` shows the Seq configuration as well.

## Testing

Seq requests do not go through the `Http` facade, so `Http::fake()` neither catches nor counts them. Disable shipping in
your `phpunit.xml`:

```xml
<env name="SEQ_ENABLED" value="false"/>
```

Or fake Seq and assert what would have been shipped:

```php
use Illuminate\Http\Client\Request;
use SchaeferSoft\Seq\Facades\Seq;

$http = Seq::fake();

Log::channel('seq')->info('Order {order_id} placed', ['order_id' => 1042]);
Seq::flush();

$http->assertSent(fn (Request $request) => str_contains($request->body(), '"order_id":1042'));
```

## Running Seq locally

```bash
docker run --name seq -d --restart unless-stopped -e ACCEPT_EULA=Y -e SEQ_FIRSTRUN_NOAUTHENTICATION=true -p 5341:80 datalust/seq
```

Seq is then available at [http://localhost:5341](http://localhost:5341). Set `SEQ_URL=http://localhost:5341` to ship to it.

## Development

```bash
composer test
composer analyse
composer format
```

The integration tests run against a real Seq server, for example the container from [Running Seq locally](#running-seq-locally):

```bash
SEQ_INTEGRATION_URL=http://localhost:5341 composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE](LICENSE).
