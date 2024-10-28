<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\ScopeInterface;
use Spiral\Telemetry\AbstractTracer;
use Spiral\Telemetry\Span;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;

final class Tracer extends AbstractTracer
{
    private ?\OpenTelemetry\API\Trace\SpanInterface $lastSpan = null;

    public function __construct(
        #[Proxy] ScopeInterface $scope,
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private array $context = [],
    ) {
        parent::__construct($scope);
    }

    /**
     * @throws \Throwable
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = false,
        ?TraceKind $traceKind = null,
        ?int $startTime = null,
    ): mixed {
        $traceSpan = $this->getTraceSpan($name, $traceKind, $startTime);
        $internalSpan = $this->createInternalSpan($name, $attributes);

        $scope = null;
        if ($scoped) {
            $scope = $traceSpan->activate();
        }

        try {
            $result = $this->runScope($internalSpan, $callback);

            if (($status = $internalSpan->getStatus()) !== null) {
                $traceSpan->setStatus($status->code, $status->description);
            }

            $traceSpan->updateName($internalSpan->getName());
            $traceSpan->setAttributes($internalSpan->getAttributes());

            return $result;
        } catch (\Throwable $e) {
            $traceSpan->recordException($e);
            throw $e;
        } finally {
            $traceSpan->end();
            $scope?->detach();
        }
    }

    public function getContext(): array
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
            default => SpanKind::KIND_INTERNAL,
        };
    }

    private function createInternalSpan(string $name, array $attributes): SpanInterface
    {
        return new Span($name, $attributes);
    }

    private function getTraceSpan(
        string $name,
        ?TraceKind $traceKind,
        ?int $startTime,
    ): \OpenTelemetry\API\Trace\SpanInterface {
        $spanBuilder = $this->tracer
            ->spanBuilder($name)
            ->setSpanKind($this->convertSpanKind($traceKind));

        if ($startTime !== null) {
            $spanBuilder->setStartTimestamp($startTime);
        }

        if ($this->context !== []) {
            $spanBuilder->setParent(
                $this->propagator->extract($this->context),
            );
        }

        return $this->lastSpan = $spanBuilder->startSpan();
    }
}
