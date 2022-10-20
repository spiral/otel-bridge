<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\ScopeInterface;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

final class TracerFactory implements TracerFactoryInterface
{
    public function __construct(
        private readonly ScopeInterface $scope,
        private readonly \OpenTelemetry\API\Trace\TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
    ) {
    }

    public function make(array $context = []): TracerInterface
    {
        $context = \array_intersect_assoc($context, $this->propagator->fields());

        return new Tracer($this->scope, $this->tracer, $this->propagator, $context);
    }
}
