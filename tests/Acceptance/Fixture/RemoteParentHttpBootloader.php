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
 * An {@see HttpStack} with a handler that answers `pong` and traces a small tree:
 *
 * ```
 * handler (scoped)
 * ├── repository (not scoped)
 * │   └── query (scoped)
 * └── view (scoped)
 *     └── template (scoped)
 * ```
 */
final class RemoteParentHttpBootloader extends Bootloader
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
            $tracer = $container->get(TracerInterface::class);
            $tracer->trace('handler', static function () use ($tracer): void {
                $tracer->trace(
                    'repository',
                    static fn() => $tracer->trace('query', static fn() => null, scoped: true),
                );
                $tracer->trace(
                    'view',
                    static fn() => $tracer->trace('template', static fn() => null, scoped: true),
                    scoped: true,
                );
            }, scoped: true);

            return 'pong';
        });
    }
}
