# This is my package opentelemetry

[![PHP Version Require](https://poser.pugx.org/spiral/opentelemetry/require/php)](https://packagist.org/packages/spiral/opentelemetry)
[![Latest Stable Version](https://poser.pugx.org/spiral/opentelemetry/v/stable)](https://packagist.org/packages/spiral/opentelemetry)
[![phpunit](https://github.com/spiral/opentelemetry/actions/workflows/phpunit.yml/badge.svg)](https://github.com/spiral/opentelemetry/actions)
[![psalm](https://github.com/spiral/opentelemetry/actions/workflows/psalm.yml/badge.svg)](https://github.com/spiral/opentelemetry/actions)
[![Codecov](https://codecov.io/gh/spiral/opentelemetry/branch/master/graph/badge.svg)](https://codecov.io/gh/spiral/opentelemetry/)
[![Total Downloads](https://poser.pugx.org/spiral/opentelemetry/downloads)](https://packagist.org/spiral/opentelemetry/phpunit)


This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.


## Requirements

Make sure that your server is configured with following PHP version and extensions:

- PHP 8.1+
- Spiral framework 3.0+

## Installation

You can install the package via composer:

```bash
composer require spiral/opentelemetry
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

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [butschster](https://github.com/spiral)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
