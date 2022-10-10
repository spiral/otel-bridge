# OpenTelemetry bridge for Spiral Framework

[![PHP Version Require](https://poser.pugx.org/spiral/otel-bridge/require/php)](https://packagist.org/packages/spiral/otel-bridge)
[![Latest Stable Version](https://poser.pugx.org/spiral/otel-bridge/v/stable)](https://packagist.org/packages/spiral/otel-bridge)
[![phpunit](https://github.com/spiral/otel-bridge/actions/workflows/phpunit.yml/badge.svg)](https://github.com/spiral/otel-bridge/actions)
[![psalm](https://github.com/spiral/otel-bridge/actions/workflows/psalm.yml/badge.svg)](https://github.com/spiral/otel-bridge/actions)
[![Codecov](https://codecov.io/gh/spiral/otel-bridge/branch/master/graph/badge.svg)](https://codecov.io/gh/spiral/otel-bridge/)
[![Total Downloads](https://poser.pugx.org/spiral/otel-bridge/downloads)](https://packagist.org/spiral/otel-bridge/phpunit)

## Requirements

Make sure that your server is configured with following PHP version and extensions:

- PHP 8.1+
- Spiral framework 3.2+

## Installation

You can install the package via composer:

```bash
composer require spiral/otel-bridge
```

After package install you need to register bootloader from the package.

```php
protected const LOAD = [
    // ...
    \Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader::class,
];
```

> Note: if you are using [`spiral-packages/discoverer`](https://github.com/spiral-packages/discoverer),
> you don't need to register bootloader by yourself.

## Configuration

```dotenv
TELEMETRY_DRIVER=otel

# OpenTelemetry
OTEL_SERVICE_NAME=php
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_PHP_TRACES_PROCESSOR=simple
```

You can run OpenTelemetry collector server and Zipkin tracing system via docker by using the example below:

```yaml
version: "3.6"

services:
  collector:
    image: otel/opentelemetry-collector-contrib
    command: ["--config=/etc/otel-collector-config.yml"]
    volumes:
      - ./otel-collector-config.yml:/etc/otel-collector-config.yml
    ports:
      - "4318:4318"

  zipkin:
    image: openzipkin/zipkin-slim
    ports:
      - "9411:9411"
```

and `otel-collector-config.yml` config file

```yaml
receivers:
  otlp:
    protocols:
      grpc:
      http:

processors:
  batch:
    timeout: 1s

exporters:
  logging:
    loglevel: debug

  zipkin:
    endpoint: "http://zipkin:9411/api/v2/spans"

service:
  pipelines:
    traces:
      receivers: [ otlp ]
      processors: [ batch ]
      exporters: [ zipkin ]
```

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
