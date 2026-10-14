<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\EnvironmentInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\FactoryInterface;
use Spiral\Http\Http;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Http\HttpStack;
use Spiral\Telemetry\TracerFactoryInterface;

/**
 * An {@see HttpStack} answering `pong` behind `ATTRIBUTES_HTTP_MIDDLEWARE` pass-through middleware,
 * so that the `HTTP Pipeline` span carries an `http.middleware` list of that many items.
 */
final class AttributesHttpBootloader extends Bootloader
{
    protected const SINGLETONS = [
        Http::class => [self::class, 'initHttp'],
    ];

    public function initHttp(
        FactoryInterface $factory,
        #[Proxy] ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
        EnvironmentInterface $env,
    ): Http {
        return HttpStack::create(
            $factory,
            $container,
            $tracerFactory,
            static fn(): string => 'pong',
            \array_fill(0, (int) $env->get('ATTRIBUTES_HTTP_MIDDLEWARE', 0), AttributesPassMiddleware::class),
        );
    }
}
