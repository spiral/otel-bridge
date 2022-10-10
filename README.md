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

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
