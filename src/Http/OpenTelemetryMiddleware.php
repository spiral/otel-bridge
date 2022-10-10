<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TracerFactoryInterface;

class OpenTelemetryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TracerFactoryInterface $tracerFactory
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->tracerFactory->fromContext($request->getHeaders())->trace(
            name: sprintf('%s %s', $request->getMethod(), $request->getUri()),
            callback: function (SpanInterface $span) use ($handler, $request) {
                $response = $handler->handle($request);

                $span
                    ->setAttribute(
                        'http.status_code',
                        $response->getStatusCode()
                    )
                    ->setAttribute(
                        'http.response_content_length',
                        $response->getHeaderLine('Content-Length') ?: $response->getBody()->getSize()
                    )
                    ->setStatus($response->getStatusCode() < 500 ? 'OK' : 'ERROR');

                return $response;
            },
            attributes: [
                'http.method' => $request->getMethod(),
                'http.url' => $request->getUri(),
            ],
            scoped: true
        );
    }
}
