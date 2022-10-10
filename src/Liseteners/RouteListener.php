<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Liseteners;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Spiral\Core\Container\SingletonInterface;
use Spiral\Router\Event\RouteMatched;
use Spiral\Router\Event\RouteNotFound;
use Spiral\Router\Event\Routing;
use Spiral\Router\Router;

class RouteListener implements SingletonInterface
{
    private readonly SpanBuilderInterface $spanBuilder;
    private SpanInterface $span;

    public function __construct(
        TracerInterface $tracer
    ) {
        $this->spanBuilder = $tracer->spanBuilder('route');
    }

    public function onRouting(Routing $event): void
    {
        $this->span = $this->spanBuilder
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
    }


    public function onRouteNotFound(RouteNotFound $event): void
    {
        $this->span->end();
    }


    public function onRouteMatched(RouteMatched $event): void
    {
        $this->span
            ->setAttribute('route.matches', $event->request->getAttribute(Router::ROUTE_MATCHES))
            ->setAttribute('route.name', $event->request->getAttribute(Router::ROUTE_NAME))
            ->setAttribute('route.attribute', $event->request->getAttribute(Router::ROUTE_ATTRIBUTE))
            ->end();
    }
}
