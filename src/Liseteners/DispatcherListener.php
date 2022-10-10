<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Liseteners;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Boot\Event\DispatcherFound;
use Spiral\Core\Container\SingletonInterface;

class DispatcherListener implements SingletonInterface
{
    private readonly SpanBuilderInterface $spanBuilder;
    private SpanInterface $span;

    public function __construct(
        TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
    ) {
        $this->spanBuilder = $tracer->spanBuilder('dispatcher');
    }

    public function onDispatcherFound(DispatcherFound $event): void
    {
        $this->span = $this->spanBuilder->startSpan()
            ->setAttribute('dispatcher', $event->dispatcher::class);

        $this->span->storeInContext(Context::getCurrent());

        $this->span->end();
    }
}
