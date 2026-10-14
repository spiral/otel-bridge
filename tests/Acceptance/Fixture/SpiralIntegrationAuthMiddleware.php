<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * Traces the user lookup as `authenticate` and passes the user on as the `user` request attribute.
 */
final class SpiralIntegrationAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->tracer->trace('authenticate', static function (SpanInterface $span): string {
            $span->setAttribute('enduser.id', 'alice');
            return 'alice';
        }, scoped: true);

        return $handler->handle($request->withAttribute('user', $user));
    }
}
