<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\EnvironmentInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\FactoryInterface;
use Spiral\Http\Config\HttpConfig;
use Spiral\Http\Http;
use Spiral\Http\LazyPipeline;
use Spiral\Telemetry\TracerFactoryInterface;

/**
 * A minimal HTTP stack of `spiral/http` with `ATTRIBUTES_HTTP_MIDDLEWARE` pass-through middleware,
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
        $responses = new Psr17Factory();
        $count = (int) $env->get('ATTRIBUTES_HTTP_MIDDLEWARE', 0);
        $http = new Http(
            new HttpConfig([
                'basePath' => '/',
                'headers' => [],
                'middleware' => \array_fill(0, $count, AttributesPassMiddleware::class),
            ]),
            $factory->make(LazyPipeline::class),
            $responses,
            $container,
            $tracerFactory,
        );

        return $http->setHandler(
            static fn(ServerRequestInterface $request): ResponseInterface => $responses
                ->createResponse(200)
                ->withBody($responses->createStream('pong')),
        );
    }
}
