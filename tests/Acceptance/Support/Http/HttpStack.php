<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Core\FactoryInterface;
use Spiral\Http\Config\HttpConfig;
use Spiral\Http\Http;
use Spiral\Http\LazyPipeline;
use Spiral\Telemetry\TracerFactoryInterface;

/**
 * The HTTP stack of `spiral/http` without spiral/framework, for the `Http` binding of a fixture bootloader.
 *
 * `Http` traces each request as a scoped SERVER span, with the incoming headers as the tracer context.
 */
final class HttpStack
{
    /**
     * @param ContainerInterface $container A `#[Proxy]` container, so that the handler resolves
     *        request-scoped services, like the tracer `Http` binds for the request span.
     * @param callable|RequestHandlerInterface $handler A callable gets the request and may return a
     *        response, or a string for the body of a 200 response.
     * @param list<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware
     */
    public static function create(
        FactoryInterface $factory,
        ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
        callable|RequestHandlerInterface $handler,
        array $middleware = [],
    ): Http {
        $http = new Http(
            new HttpConfig(['basePath' => '/', 'headers' => [], 'middleware' => $middleware]),
            $factory->make(LazyPipeline::class),
            new Psr17Factory(),
            $container,
            $tracerFactory,
        );

        return $http->setHandler($handler);
    }
}
