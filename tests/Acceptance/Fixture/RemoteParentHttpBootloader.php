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
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * The `spiral/http` stack of {@see HttpBootloader} with a handler that traces a small tree:
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
                # Inside a request the container hands out the tracer Http bound for the request span.
                $tracer = $this->container->get(TracerInterface::class);
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

                return $this->responses->createResponse(200)->withBody($this->responses->createStream('pong'));
            }
        });
    }
}
