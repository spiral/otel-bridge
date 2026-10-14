<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\FactoryInterface;
use Spiral\Http\Http;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Http\HttpStack;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * An {@see HttpStack} with a handler that answers with the status code from the `code` query parameter.
 * With an `upstream` parameter the handler first traces an outgoing call as a CLIENT span named
 * `upstream` whose status is that integer HTTP code.
 */
final class StatusCodeHttpBootloader extends Bootloader
{
    protected const SINGLETONS = [
        Http::class => [self::class, 'initHttp'],
    ];

    public function initHttp(
        FactoryInterface $factory,
        #[Proxy] ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
    ): Http {
        return HttpStack::create(
            $factory,
            $container,
            $tracerFactory,
            static function (ServerRequestInterface $request) use ($container): ResponseInterface {
                $query = $request->getQueryParams();

                if (isset($query['upstream'])) {
                    $upstream = (int) $query['upstream'];
                    $container->get(TracerInterface::class)->trace(
                        'upstream',
                        static fn(SpanInterface $span) => $span->setStatus($upstream),
                        traceKind: TraceKind::CLIENT,
                    );
                }

                return (new Psr17Factory())->createResponse((int) ($query['code'] ?? 200));
            },
        );
    }
}
