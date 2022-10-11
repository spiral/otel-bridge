<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\InvokerInterface;
use Spiral\Core\ScopeInterface;
use Spiral\Telemetry\Span;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerInterface;

final class Tracer implements TracerInterface
{
    private ?array $context = null;
    private ?\OpenTelemetry\API\Trace\SpanInterface $lastSpan = null;

    public function __construct(
        private readonly \OpenTelemetry\API\Trace\TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private readonly InvokerInterface $invoker,
        private readonly ScopeInterface $scope
    ) {
    }

    public function withContext(?array $context): self
    {
        $self = clone $this;
        $self->context = $context;

        return $self;
    }

    /**
     * @throws \Throwable
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = false,
        bool $debug = false,
        ?TraceKind $traceKind = null,
        ?int $startTime = null
    ): mixed {
        $spanBuilder = $this->tracer->spanBuilder($name)
            ->setSpanKind($this->convertSpanKind($traceKind));

        if ($startTime !== null) {
            $spanBuilder->setStartTimestamp($startTime);
        }

        if ($this->context !== null) {
            $ctx = $this->propagator->extract($this->context);
            $spanBuilder->setParent($ctx);
        }

        $traceSpan = $spanBuilder->startSpan();
        $this->lastSpan = $traceSpan;

        $span = $this->createSpan($name, $attributes);

        $scope = null;
        if ($scoped) {
            $scope = $traceSpan->activate();
        }

        try {
            $result = $this->scope->runScope([
                SpanInterface::class => $span,
            ], fn() => $this->invoker->invoke($callback));

            if (($status = $span->getStatus()) !== null) {
                $traceSpan->setStatus($status->code, $status->description);
            }

            $traceSpan->updateName($span->getName());
            $traceSpan->setAttributes($span->getAttributes());

            return $result;
        } catch (\Throwable $e) {
            $traceSpan->recordException($e);
            throw $e;
        } finally {
            $traceSpan->end();
            $scope?->detach();
        }
    }

    public function getContext(): ?array
    {
        if ($this->lastSpan !== null) {
            $ctx = $this->lastSpan->storeInContext(Context::getCurrent());
            $carrier = [];
            $this->propagator->inject($carrier, null, $ctx);

            return $carrier;
        }

        return $this->context;
    }

    public function convertSpanKind(?TraceKind $traceKind): int
    {
        return match ($traceKind) {
            TraceKind::CLIENT => SpanKind::KIND_CLIENT,
            TraceKind::SERVER => SpanKind::KIND_SERVER,
            TraceKind::PRODUCER => SpanKind::KIND_PRODUCER,
            TraceKind::CONSUMER => SpanKind::KIND_CONSUMER,
            default => SpanKind::KIND_INTERNAL
        };
    }

    public function createSpan(string $name, array $attributes): SpanInterface
    {
        return new Span($name, $attributes);
    }
}
