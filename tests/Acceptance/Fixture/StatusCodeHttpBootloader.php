<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\FactoryInterface;
use Spiral\Http\Config\HttpConfig;
use Spiral\Http\Http;
use Spiral\Http\LazyPipeline;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * The `spiral/http` stack of {@see HttpBootloader} with a handler that answers with the status code
 * from the `code` query parameter. With an `upstream` parameter the handler first traces an outgoing
 * call as a CLIENT span named `upstream` whose status is that integer HTTP code.
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
        $responses = new Psr17Factory();
        $http = new Http(
            new HttpConfig(['basePath' => '/', 'headers' => [], 'middleware' => []]),
            $factory->make(LazyPipeline::class),
            $responses,
            $container,
            $tracerFactory,
        );

        return $http->setHandler(new class($container, $responses) implements RequestHandlerInterface {
            public function __construct(
                private readonly ContainerInterface $container,
                private readonly Psr17Factory $responses,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $query = $request->getQueryParams();

                if (isset($query['upstream'])) {
                    $upstream = (int) $query['upstream'];
                    $this->container->get(TracerInterface::class)->trace(
                        'upstream',
                        static fn(SpanInterface $span) => $span->setStatus($upstream),
                        traceKind: TraceKind::CLIENT,
                    );
                }

                return $this->responses->createResponse((int) ($query['code'] ?? 200));
            }
        });
    }
}
