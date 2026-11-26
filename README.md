<p align="center">
    <a href="https://spiral.dev"><img alt="Spiral Framework" src="https://github.com/spiral/.github/raw/main/logo.svg" style="display: block"></a>
</p>

<p align="center">OpenTelemetry tracing for Spiral Framework</p>

<div align="center">

[![Documentation](https://img.shields.io/badge/Documentation-blue?style=for-the-badge&logo=gitbook&logoColor=white)](https://spiral.dev/docs/advanced-telemetry)
[![Sponsor](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsor&logo=githubsponsors&logoColor=white&color=%23EA4AAA)](https://github.com/sponsors/spiral)

[![Psalm Level](https://shepherd.dev/github/spiral/otel-bridge/level.svg)](https://shepherd.dev/github/spiral/otel-bridge)
[![Type Coverage](https://shepherd.dev/github/spiral/otel-bridge/coverage.svg)](https://shepherd.dev/github/spiral/otel-bridge)
[![Codecov](https://codecov.io/gh/spiral/otel-bridge/branch/2.x/graph/badge.svg)](https://codecov.io/gh/spiral/otel-bridge)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fspiral%2Fotel-bridge%2F2.x)](https://dashboard.stryker-mutator.io/reports/github.com/spiral/otel-bridge/2.x)

</div>

<br />

This package plugs the [OpenTelemetry PHP SDK](https://opentelemetry.io/docs/languages/php/) into the Spiral telemetry component as the `otel` tracer driver, so the spans of your application are exported to any OpenTelemetry-compatible backend and the trace context is propagated between services with W3C `traceparent` headers.

## Get Started

### Installation

```bash
composer require spiral/otel-bridge
```

[![PHP](https://img.shields.io/packagist/php-v/spiral/otel-bridge.svg?style=flat-square&logo=php)](https://packagist.org/packages/spiral/otel-bridge)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/spiral/otel-bridge.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/spiral/otel-bridge)
[![License](https://img.shields.io/packagist/l/spiral/otel-bridge.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/spiral/otel-bridge.svg?style=flat-square)](https://packagist.org/packages/spiral/otel-bridge/stats)

Install an exporter as well, for example OTLP:

```bash
composer require open-telemetry/exporter-otlp
```

Then add the bootloader to your application kernel:

```php
public function defineBootloaders(): array
{
    return [
        // ...
        \Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader::class,
        // ...
    ];
}
```

### Configuration

Select the `otel` driver and configure the SDK with the standard `OTEL_*` environment variables:

```dotenv
# Telemetry driver [log, null, otel]
TELEMETRY_DRIVER=otel

OTEL_SERVICE_NAME=php # Your application name
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

The exporter is created lazily, on the first export. The full list of variables is in the [OpenTelemetry SDK configuration](https://opentelemetry.io/docs/languages/sdk-configuration/).

The trace context is read from and written to headers with the propagators of `OTEL_PROPAGATORS`, by default W3C Trace Context and Baggage. Other propagators need their package, for example `b3` needs `open-telemetry/extension-propagator-b3`: a name the SDK does not know is skipped with a warning, and with none left the propagator is a no-op one.

`getContext()` depends on where it is called:

- before the tracer has started any trace, it returns the incoming headers the tracer was made with, baggage included, limited to the fields of the propagator;
- while a trace runs, the context of the span running there, with the baggage current there, or the incoming baggage when no baggage is current;
- once the traces of the tracer have ended, as when `Spiral\Http\Http` adds the context to the response, the trace context of the last finished outermost trace, without baggage.

The incoming baggage is current inside a scoped trace that takes the incoming parent, so code reads it with `Baggage::getCurrent()` and can extend it.

With `OTEL_PHP_TRACES_PROCESSOR=batch` (the SDK default) spans are queued and flushed after every request or job,
and on application shutdown, through the Spiral finalizer. Spans still queued are lost when the worker is killed hard,
e.g. by `SIGKILL` or the RoadRunner stop timeout.

The names, attributes and status of the spans of Spiral components, like the SERVER span of a request and its HTTP attributes, are set by those components, `spiral/http` among them; the bridge exports them as they are.

### Current span

Code that has no span passed to it gets the innermost span it runs in with `CurrentSpan::get()` of spiral/telemetry. The bridge tells it the spans that only OpenTelemetry knows of:

```php
use Spiral\Telemetry\CurrentSpan;

CurrentSpan::get()->setAttribute('dict.org_types.count', \count($types));
```

- Inside a trace, scoped or not, it is the same Spiral span the traced callback gets.
- Inside an OpenTelemetry span activated within the trace, as the span of a [`#[WithSpan]`](#withspan) method or of a hook, it is that span; once the span is detached, as when the method returns, it is the trace's again.
- Outside a Spiral trace, as in a console command calling a `#[WithSpan]` method, it is the active OpenTelemetry span: the bridge sets itself as the provider of `CurrentSpan` when the app boots.
- Outside a running trace and an active span, it is a span that drops everything.

Values set on it are converted as for the span of a trace. On an OpenTelemetry span they are written at once, as the SDK has it: the getters give the converted values, `setAttributes()` adds to the attributes rather than replacing them, an `Ok` status is final, and a status that converts to `Unset` is ignored.

A span that OpenTelemetry does not record drops the values. Besides a span not sampled, that is the case in a span suppressed inside an OpenTelemetry span the trace activated, and in a remote context activated inside a trace. A span suppressed right inside the trace, like that of the psr15 hook on a middleware, is taken for the trace. A context that outlives its trace or span, captured and activated again later or left attached, gives what is innermost around the ended ones: the trace they are nested in, an OpenTelemetry span still running, or else the span that drops everything. Of ended OpenTelemetry spans, only a child of the span active in the trace, as that of a `#[WithSpan]` method right inside it, is seen through, as the SDK keeps the id of a span's parent, not the parent. It is told by that id alone, so an ended span given the same parent by hand is seen through too, and in a trace with no span around it, so is an ended root span.

For events, exceptions recorded by hand, links and the trace id, use `OpenTelemetry\API\Trace\Span::getCurrent()`: it gives the active OpenTelemetry span, which is a Spiral trace only when it is scoped.

The span is found in the OpenTelemetry context, which is kept per fiber. The callback of a trace sees the context of the trace in whatever fiber spiral/telemetry runs it. A fiber started inside a trace gets the trace from spiral/telemetry, but has no OpenTelemetry context until it activates one, or `OTEL_PHP_FIBERS_ENABLED` forks the context into new fibers over FFI. Until then, reading the context raises a warning, which the error handler of a Spiral app turns into an exception, and `CurrentSpan::get()` gives the span of the trace.

### Span status

A status set on a Spiral span is exported as the OpenTelemetry span status:

- the strings `ok` and `error`, in any case, give `Ok` and `Error`; any other string leaves the status `Unset`;
- the integers `0`, `1` and `2` are the OTLP values of `Unset`, `Ok` and `Error`;
- any other integer is taken for an HTTP status code: 1xx–3xx leave the status `Unset`, 4xx is `Error` except on a `SERVER` span, 5xx is `Error`, and a number out of the HTTP range is `Error`.

When the traced callback throws, the span keeps its name and attributes and gets status `Error` with the exception message, unless the callback set `Ok` or `Error`.

## Auto-instrumentation with ext-opentelemetry

The bridge works with and without the [`opentelemetry` extension](https://opentelemetry.io/docs/zero-code/php/). With it, auto-instrumentation packages (`open-telemetry/opentelemetry-auto-*`) add spans for code Spiral does not trace: database drivers, HTTP clients, caches.

The spans of those hooks and the Spiral spans form one trace and go through one SDK pipeline:

- When the app boots, the bridge registers its tracer provider and propagator as the global ones that auto-instrumentation takes from `OpenTelemetry\API\Globals`. The provider is still built on first use. Spans of hooks are flushed together with the Spiral spans at the end of each request or job, so under RoadRunner they are not held until the worker exits.
- With `OTEL_PHP_AUTOLOAD_ENABLED=true` the SDK autoloader configures the global provider and propagator, from the env or from the file of `OTEL_CONFIG_FILE` (`OTEL_EXPERIMENTAL_CONFIG_FILE` before SDK 1.14), which needs `open-telemetry/sdk-configuration`, and the bridge takes them from `Globals`. The bridge flushes that provider after every request or job, while shutting it down is left to the SDK at process exit. The autoloader decides when Composer loads, so the variable has to be in the process environment or `php.ini`: set only in `.env`, it is reported and the bridge builds its own provider.
- Spans that hooks start while the exporter sends a batch, like the PSR-18 or curl request of the OTLP exporter itself, are not recorded, so that every flush does not send a new trace of its own.

### Packages

Most packages register their hooks on classes, interfaces and functions when Composer loads their `autoload.files` entry, `_register.php`. For them installing the package is all it takes: nothing has to be turned on, and `OTEL_PHP_AUTOLOAD_ENABLED` is not needed, since the hooks take the tracer and propagator from `Globals`, where the bridge puts its own. The packages require `ext-opentelemetry`; where the extension is not loaded at runtime, `_register.php` triggers a warning and registers nothing.

```bash
pie install open-telemetry/ext-opentelemetry
composer require open-telemetry/opentelemetry-auto-guzzle
```

PIE enables the extension; if `php -m` does not list `opentelemetry`, add `extension=opentelemetry` to `php.ini`.

Packages moved to SPI register through the SDK autoloader only, which runs with `OTEL_PHP_AUTOLOAD_ENABLED=true` in the process environment or `php.ini` and needs the `tbachert/spi` Composer plugin allowed in `config.allow-plugins`. With it, the bridge takes the provider of the SDK, with the consequences described above. `psr18` registers this way from the release after 1.4.0.

Packages useful in a Spiral app, all `open-telemetry/opentelemetry-auto-*`:

| Package | Name | Hooks |
|---|---|---|
| `psr18` | `psr18` | `sendRequest()` of any PSR-18 client |
| `guzzle` | `guzzle` | every Guzzle request: `send()`, `request()`, `get()` and the like, sync and async |
| `symfony` | `symfony` | `request()` of Symfony HttpClient, together with HttpKernel and Messenger; requires `symfony/http-kernel` |
| `curl` | `curl` | `curl_exec()` and `curl_multi_*` |
| `pdo` | `pdo` | `PDO` and `PDOStatement`: connect, query, exec, prepare, execute, transactions |
| `mysqli` | `mysqli` | `mysqli` functions and methods |
| `postgresql` | `postgresql` | `pg_*` functions of `ext-pgsql` |
| `psr16` | `psr16` | PSR-16 `CacheInterface`, which the `spiral/cache` storages implement; `CacheManager::storage()` returns a `CacheRepository`, itself a `CacheInterface` that calls the storage, so each cache call through Spiral gives two nested spans |
| `ext-amqp` | `ext_amqp` | `AMQPExchange::publish()` and `AMQPQueue` ack, nack, reject of `ext-amqp` |

The HTTP client packages add the propagator headers, `traceparent` among them, to the request. `psr18` sees only `sendRequest()`: Guzzle's `request()` or Symfony's `request()` need their own package. A client called through `sendRequest()` with both `psr18` and its own package installed gives two nested `CLIENT` spans, and `curl` adds one more under clients that use curl, like the curl handler of Guzzle or `CurlHttpClient` of Symfony. Disable the outer package, or set `OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY=spankind` (see [PSR-15](#psr-15)). Disabling `psr18` also drops the spans of PSR-18 clients that have no package of their own.

### Disabling

`OTEL_PHP_DISABLED_INSTRUMENTATIONS` takes a comma-separated list of the names from the table, or `all`:

```bash
export OTEL_PHP_DISABLED_INSTRUMENTATIONS=guzzle,psr18
```

It is read while Composer autoloads, by each `_register.php` or by the SDK autoloader, before Spiral loads `.env`, so, as with `OTEL_PHP_AUTOLOAD_ENABLED`, it has to be in the process environment or `php.ini`: the SDK reads `getenv()`, then `$_SERVER`, then `get_cfg_var()`. Set only in `.env`, it has no effect. For example:

- RoadRunner: `server.env` in `.rr.yaml`, or the environment of the `rr` process (Docker, systemd):

  ```yaml
  server:
    env:
      OTEL_PHP_DISABLED_INSTRUMENTATIONS: guzzle
  ```

- php-fpm: `env[OTEL_PHP_DISABLED_INSTRUMENTATIONS] = guzzle` in the pool;
- `php.ini`: `OTEL_PHP_DISABLED_INSTRUMENTATIONS = guzzle`.

Under RoadRunner the hooks are registered once per worker, when it starts, so a new value applies to new workers only: a `php.ini` change takes effect on `rr reset`; a change in `.rr.yaml` or in the environment of `rr`, on a restart of `rr`.

`OTEL_SDK_DISABLED=true` does not remove the hooks, as the `_register.php` files do not check it. The bridge then builds a no-op provider, so nothing is exported, but the hooks still run on every call. To drop them, list them in `OTEL_PHP_DISABLED_INSTRUMENTATIONS`.

### PSR-15

The HTTP stack of Spiral is PSR-15, so `open-telemetry/opentelemetry-auto-psr15` hooks into it. With the provider built by the bridge, the spans in which it doubles Spiral are suppressed, and the tree of a request is the same as without the extension:

- its `SERVER` span of `Spiral\Http\Http::handle()` is suppressed, and the `SERVER` span of Spiral stays the root of the request, under the incoming parent;
- its `INTERNAL` spans of every request handler and middleware are suppressed, as Spiral traces its middleware pipeline itself.

Disabling that instrumentation with `OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr15` (see [Disabling](#disabling)) gives the same tree.

Nothing else is suppressed: a request handled inside a request or a job run inside a job keeps both spans. Suppression by span kind or by semantic conventions is opt-in, with `OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY=spankind` or `semconv`, as the SDK reads it.

With `OTEL_PHP_AUTOLOAD_ENABLED=true` the provider comes from the SDK, so the bridge cannot suppress anything: disable psr15 or configure suppression in the SDK.

A span left active when a request or job ends would become the parent of every later span of the worker and hide the incoming trace context; the bridge writes a warning to the SDK log when it sees one.

Spans of hooks nest under the active span. A Spiral trace that is not `scoped`, like `Routing`, is never active, so database or HTTP client spans made inside it hang on the enclosing scoped span.

### WithSpan

With `opentelemetry.attr_hooks_enabled=1` in `php.ini`, the extension traces a function or method marked with `#[OpenTelemetry\API\Instrumentation\WithSpan]`. Its span nests like the spans of hooks, and inside the method [`CurrentSpan::get()`](#current-span) gives it:

```php
use OpenTelemetry\API\Instrumentation\WithSpan;
use Spiral\Telemetry\CurrentSpan;

#[WithSpan('dict.load')]
public function load(): array
{
    $types = $this->fetchTypes();
    CurrentSpan::get()->setAttribute('dict.org_types.count', \count($types));

    return $types;
}
```

## Upgrading to 2.0

- The instrumentation scope of the Spiral spans is `spiral/otel-bridge` with the package version, not `OTEL_SERVICE_NAME`. The service name stays a resource attribute. The version comes from `resources/version.json`, the release manifest, which moved there from `.github/`.
- The propagator is built from `OTEL_PROPAGATORS`, by default `tracecontext,baggage`, and is a singleton.
- The tracer provider and propagator are registered in `Globals` from boot until the app terminates; with `OTEL_PHP_AUTOLOAD_ENABLED=true` in the process environment they are taken from there instead.
- Status codes: integers and unknown strings no longer give `Ok`, see [Span status](#span-status).
- A callback that throws makes the span `Error` with the exception message and keeps the name and attributes set in it.
- `getContext()` returns the context of the span running where it is called, with the baggage; after all traces have ended, the trace context of the last finished outermost one, without baggage.
- With `opentelemetry-auto-psr15`, its spans that double Spiral ones are suppressed.
- `OpenTelemetryBootloader::initTraceProvider()` takes the span processor from the container instead of a parameter.
- Dependencies: Spiral packages `^3.18`, `open-telemetry/sdk` `>=1.9 <1.16`, `open-telemetry/api` `^1.7`, `open-telemetry/context` `^1.4`; `open-telemetry/opentelemetry-auto-psr15` conflicts outside `>=1.0.6 <1.4`, `open-telemetry/sem-conv` below `1.22`.
