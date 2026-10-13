<p align="center">
    <a href="https://spiral.dev"><img alt="Spiral Framework" src="https://github.com/spiral/.github/raw/main/logo.svg" style="width: 3in; display: block"></a>
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
