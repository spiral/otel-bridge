<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\FactoryInterface;
use Spiral\Http\Http;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Http\HttpStack;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * An {@see HttpStack} without middleware and with one handler answering `pong` from inside a nested
 * `handler` trace.
 */
final class HttpBootloader extends Bootloader
{
    protected const SINGLETONS = [
        Http::class => [self::class, 'initHttp'],
    ];

    public function initHttp(
        FactoryInterface $factory,
        #[Proxy] ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
    ): Http {
        return HttpStack::create($factory, $container, $tracerFactory, static function () use ($container): string {
            # Inside a request the container hands out the tracer Http bound for the request span.
            $container->get(TracerInterface::class)->trace('handler', static fn() => null, scoped: true);

            return 'pong';
        });
    }
}
