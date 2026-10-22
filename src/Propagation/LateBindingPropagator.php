<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Propagation;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * A propagator taken from the container on first use, so the one registered for auto-instrumentation at
 * boot is the one the app ends up binding, even when a later bootloader overrides the binding.
 *
 * @internal
 */
final class LateBindingPropagator implements TextMapPropagatorInterface
{
    private ?TextMapPropagatorInterface $propagator = null;

    /**
     * @param \Closure(): TextMapPropagatorInterface $factory
     */
    public function __construct(
        private readonly \Closure $factory,
    ) {}

    #[\Override]
    public function fields(): array
    {
        return $this->propagator()->fields();
    }

    #[\Override]
    public function inject(mixed &$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        $this->propagator()->inject($carrier, $setter, $context);
    }

    #[\Override]
    public function extract(mixed $carrier, ?PropagationGetterInterface $getter = null, ?ContextInterface $context = null): ContextInterface
    {
        return $this->propagator()->extract($carrier, $getter, $context);
    }

    private function propagator(): TextMapPropagatorInterface
    {
        return $this->propagator ??= ($this->factory)();
    }
}
