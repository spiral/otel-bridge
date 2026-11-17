<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\ScopeInterface;
use Spiral\OpenTelemetry\Propagation\TracestateGetter;
use Spiral\OpenTelemetry\Span\ActiveTrace;
use Spiral\OpenTelemetry\Span\SpanConverter;
use Spiral\Telemetry\AbstractTracer;
use Spiral\Telemetry\Span;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;

final class Tracer extends AbstractTracer
{
    /**
     * The traces of this tracer that are running, innermost last, with whether each is scoped. Only
     * {@see self::getContext()} reads it: the parent of a new span is the active one, see
     * {@see self::getTraceSpan()}, so spans of other instrumentation nest between the bridge's own.
     *
     * @var list<array{\OpenTelemetry\API\Trace\SpanInterface, bool}>
     */
    private array $running = [];

    private ?\OpenTelemetry\API\Trace\SpanInterface $lastRoot = null;
    private ?ContextInterface $incoming = null;
    private readonly TracestateGetter $getter;

    public function __construct(
        #[Proxy] ScopeInterface $scope,
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private array $context = [],
    ) {
        parent::__construct($scope);
        $this->getter = new TracestateGetter();
    }

    /**
     * @param non-empty-string $name
     *
     * @throws \Throwable
     *
     * @psalm-suppress MixedAssignment The callback result is returned as is
     */
    #[\Override]
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = false,
        ?TraceKind $traceKind = null,
        ?int $startTime = null,
    ): mixed {
        $current = $outer = Context::getCurrent();
        # An explicit parent overrides the active span, so the remote one is set only for a span with no
        # local parent; otherwise every span of the request would hang off it directly.
        $remote = $this->context !== [] && !OtelSpan::fromContext($current)->getContext()->isValid();
        $traceSpan = $this->getTraceSpan($name, $traceKind, $startTime, $remote ? $current : null);
        $internalSpan = $this->createInternalSpan($name, $attributes);

        if ($scoped) {
            # The incoming baggage comes with the remote parent, so the code sees it and extends it.
            if ($remote && Baggage::fromContext($current)->isEmpty()) {
                $current = Baggage::fromContext($this->incoming())->storeInContext($current);
            }
            $current = $traceSpan->storeInContext($current);
        }
        # Activated for a trace that is not scoped as well, for CurrentSpan: the OTel span stays out of it.
        $active = ActiveTrace::start($internalSpan, $outer, $current);
        $scope = $active->storeInContext($current)->activate();

        $this->running[] = [$traceSpan, $scoped];
        $error = null;
        try {
            return $this->runScope($internalSpan, $callback);
        } catch (\Throwable $e) {
            $traceSpan->recordException($e);
            throw $error = $e;
        } finally {
            try {
                self::copySpan($internalSpan, $traceSpan, $traceKind, $error);
            } finally {
                # Each step runs even when the one before throws: a span processor in end(), or detach()
                # in a Spiral app when the callback left a scope attached and debug scopes are on.
                try {
                    $traceSpan->end();
                } finally {
                    $active->end();
                    try {
                        $scope->detach();
                    } finally {
                        $this->leave($traceSpan);
                    }
                }
            }
        }
    }

    /**
     * The headers that continue the trace where the code runs. The innermost running trace of this tracer
     * decides: when it is scoped, the span active in the context, which may be a span created inside it by
     * other instrumentation; otherwise the trace itself, as its span is never activated. With no trace running,
     * the last finished outermost one, then the incoming context.
     */
    #[\Override]
    public function getContext(): array
    {
        $top = \end($this->running);
        $current = Context::getCurrent();
        $active = OtelSpan::fromContext($current);
        $span = match (true) {
            $top === false => $this->lastRoot,
            $top[1] && $active->getContext()->isValid() => $active,
            default => $top[0],
        };

        if ($span === null) {
            return $this->context;
        }

        # Baggage goes on with a running trace only: once the trace is over, as for the response headers,
        # only the trace context is returned. A trace that is not scoped never activates the incoming
        # baggage, so it is taken from the incoming context while nothing else is active.
        $current = match (true) {
            $top === false => Baggage::getEmpty()->storeInContext($current),
            $this->context !== [] && Baggage::fromContext($current)->isEmpty()
                => Baggage::fromContext($this->incoming())->storeInContext($current),
            default => $current,
        };

        $carrier = [];
        $this->propagator->inject($carrier, null, $span->storeInContext($current));

        /** @var array<non-empty-string, string> $carrier The default setter fills the array by reference */
        return $carrier;
    }

    /**
     * @return SpanKind::KIND_*
     */
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

    /**
     * Copy what the callback set on the Spiral span to the OTel one, on the success path and the exception
     * path alike. An exception makes the span Error with its message, unless the callback has set a status:
     * Ok is final, and an Error keeps the callback's own description.
     */
    private static function copySpan(
        SpanInterface $from,
        \OpenTelemetry\API\Trace\SpanInterface $to,
        ?TraceKind $traceKind,
        ?\Throwable $error,
    ): void {
        $status = $from->getStatus();
        $code = $status === null ? StatusCode::STATUS_UNSET : SpanConverter::statusCode($status->code, $traceKind);

        if ($status !== null && $code !== StatusCode::STATUS_UNSET) {
            $to->setStatus($code, $status->description);
        } elseif ($error !== null) {
            $to->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        }

        $to->updateName($from->getName());
        $to->setAttributes(SpanConverter::attributes($from->getAttributes()));
    }

    /**
     * The incoming context, extracted once: an invalid header is then reported once, not on every call.
     */
    private function incoming(): ContextInterface
    {
        /** @psalm-suppress InternalMethod Over the root, so the result holds nothing but what was extracted */
        return $this->incoming ??= $this->propagator->extract($this->context, $this->getter, Context::getRoot());
    }

    /**
     * Take an ended trace off the stack. Not always the top one: traces in fibers that share the tracer
     * end in any order.
     */
    private function leave(\OpenTelemetry\API\Trace\SpanInterface $span): void
    {
        for ($i = \count($this->running) - 1; $i >= 0; --$i) {
            if ($this->running[$i][0] === $span) {
                \array_splice($this->running, $i, 1);
                break;
            }
        }

        if ($this->running === []) {
            $this->lastRoot = $span;
        }
    }

    /**
     * @param non-empty-string $name
     */
    private function createInternalSpan(string $name, array $attributes): SpanInterface
    {
        return new Span($name, $attributes);
    }

    /**
     * @param non-empty-string $name
     * @param ContextInterface|null $remoteOver The context to put the remote parent in, when the span gets it.
     */
    private function getTraceSpan(
        string $name,
        ?TraceKind $traceKind,
        ?int $startTime,
        ?ContextInterface $remoteOver,
    ): \OpenTelemetry\API\Trace\SpanInterface {
        $spanBuilder = $this->tracer
            ->spanBuilder($name)
            ->setSpanKind($this->convertSpanKind($traceKind));

        if ($startTime !== null) {
            $spanBuilder->setStartTimestamp($startTime);
        }

        if ($remoteOver !== null) {
            $spanBuilder->setParent(OtelSpan::fromContext($this->incoming())->storeInContext($remoteOver));
        }

        return $spanBuilder->startSpan();
    }
}
