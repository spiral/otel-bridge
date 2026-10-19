<p align="center">
    <a href="https://spiral.dev"><img alt="Spiral Framework" src="https://github.com/spiral/.github/raw/main/logo.svg" style="display: block"></a>
</p>

<p align="center">OpenTelemetry tracing for Spiral Framework</p>

<div align="center">

[![Documentation](https://img.shields.io/badge/Documentation-blue?style=for-the-badge&logo=gitbook&logoColor=white)](https://spiral.dev/docs/advanced-telemetry)
[![Sponsor](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsor&logo=githubsponsors&logoColor=white&color=%23EA4AAA)](https://github.com/sponsors/spiral)

[![Psalm Level](https://shepherd.dev/github/spiral/otel-bridge/level.svg)](https://shepherd.dev/github/spiral/otel-bridge)
[![Type Coverage](https://shepherd.dev/github/spiral/otel-bridge/coverage.svg)](https://shepherd.dev/github/spiral/otel-bridge)
[![Codecov](https://codecov.io/gh/spiral/otel-bridge/branch/master/graph/badge.svg)](https://codecov.io/gh/spiral/otel-bridge)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fspiral%2Fotel-bridge%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/spiral/otel-bridge/master)

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
OTEL_PHP_TRACES_PROCESSOR=simple
```

The exporter is created lazily, on the first export. The full list of variables is in the [OpenTelemetry SDK configuration](https://opentelemetry.io/docs/languages/sdk-configuration/).

The trace context is read from and written to headers with the propagators of `OTEL_PROPAGATORS`, by default W3C Trace Context and Baggage.

With `OTEL_PHP_TRACES_PROCESSOR=batch` (the SDK default) spans are queued and flushed after every request or job,
and on application shutdown, through the Spiral finalizer. Spans still queued are lost when the worker is killed hard,
e.g. by `SIGKILL` or the RoadRunner stop timeout.

### Span status

A status set on a Spiral span is exported as the OpenTelemetry span status:

- the strings `ok` and `error`, in any case, give `Ok` and `Error`; any other string leaves the status `Unset`;
- the integers `0`, `1` and `2` are the OTLP values of `Unset`, `Ok` and `Error`;
- any other integer is taken for an HTTP status code: 1xx–3xx leave the status `Unset`, 4xx is `Error` except on a `SERVER` span, 5xx is `Error`, and a number out of the HTTP range is `Error`.

When the traced callback throws, the span keeps its name and attributes and gets status `Error` with the exception message, unless the callback has set a status itself.

## Auto-instrumentation with ext-opentelemetry

The bridge works with and without the [`opentelemetry` extension](https://opentelemetry.io/docs/zero-code/php/). With it, auto-instrumentation packages (`open-telemetry/opentelemetry-auto-*`) add spans for code Spiral does not trace: database drivers, HTTP clients, caches.

The spans of those hooks and the Spiral spans form one trace and go through one SDK pipeline:

- When the app boots, the bridge registers its tracer provider and propagator as the global ones that auto-instrumentation takes from `OpenTelemetry\API\Globals`. The provider is still built on first use. Spans of hooks are flushed together with the Spiral spans at the end of each request or job, so under RoadRunner they are not held until the worker exits.
- With `OTEL_PHP_AUTOLOAD_ENABLED=true` the SDK autoloader configures the global provider and propagator, from the env or from `OTEL_CONFIG_FILE`, and the bridge takes them from `Globals`. The bridge flushes that provider after every request or job, while shutting it down is left to the SDK at process exit.
- Spans that hooks start while the exporter sends a batch, like the PSR-18 or curl request of the OTLP exporter itself, are not sampled, so that every flush does not send a new trace of its own. This relies on a parent-based sampler, the SDK default.

### PSR-15

The HTTP stack of Spiral is PSR-15, so `open-telemetry/opentelemetry-auto-psr15` hooks into it. With the provider built by the bridge, the spans it would double are suppressed:

- its `SERVER` span is the root of the request, and the `SERVER` span of Spiral is suppressed inside it, as is any span inside a span of the same kind, `INTERNAL` aside;
- its `INTERNAL` spans of every request handler and middleware are suppressed, as Spiral traces its middleware pipeline itself.

The tree is then the same as without the extension, except for the root span, which carries the attributes of the psr15 instrumentation. If you prefer the Spiral `SERVER` span, disable that instrumentation instead:

```dotenv
OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr15
```

With `OTEL_PHP_AUTOLOAD_ENABLED=true` the provider comes from the SDK, so the bridge cannot suppress anything: disable psr15 or configure suppression in the SDK.

A span left active when a request or job ends would become the parent of every later span of the worker and hide the incoming trace context; the bridge writes a warning to the SDK log when it sees one.

Spans of hooks nest under the active span. A Spiral trace that is not `scoped`, like `Routing` or `Job push`, is never active, so database or HTTP client spans made inside it hang on the enclosing scoped span.

## Upgrading to 2.0

- The instrumentation scope of the Spiral spans is `spiral/otel-bridge` with the package version, not `OTEL_SERVICE_NAME`. The service name stays a resource attribute.
- The propagator is built from `OTEL_PROPAGATORS` and is a singleton. By default it is `tracecontext,baggage`, so `getContext()` passes `baggage` on too.
- Status codes: integers and unknown strings no longer give `Ok`, see [Span status](#span-status).
- `getContext()` returns the context of the span running where it is called; after all traces have ended, that of the last finished outermost one.
- The bridge registers its tracer provider and propagator in `Globals` at boot, or with `OTEL_PHP_AUTOLOAD_ENABLED=true` takes them from there.
- `open-telemetry/sdk` 1.9 or newer is required, up to the latest minor version tested.
