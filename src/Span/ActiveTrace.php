<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Span;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanInterface as OtelSpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use Spiral\Telemetry\SpanInterface;

/**
 * A trace of {@see \Spiral\OpenTelemetry\Tracer}, kept in the OTel context for
 * {@see \Spiral\OpenTelemetry\CurrentSpan}.
 *
 * Spans are told apart by id rather than by object, as a suppressed span is a new non-recording wrapper of
 * its parent's span context; for the same reason only a wrapper of a span a trace knows is recognized, not
 * one of a span activated inside the trace. The invalid id matches on purpose: inside a trace with no span
 * around it, anything with that id, like `Span::getInvalid()`, means no span.
 *
 * @internal
 */
final class ActiveTrace
{
    /** @var ContextKeyInterface<self>|null */
    private static ?ContextKeyInterface $key = null;

    private bool $ended = false;

    /**
     * @param self|null $parent The trace whose context this one started in.
     * @param OtelSpanInterface $enclosingSpan The OTel span active where the trace started.
     * @param string $activeSpanId The id of the OTel span active in the trace's own context: the trace's
     *        span when it is scoped, the enclosing one otherwise. A span with another id active later was
     *        activated inside the trace.
     */
    private function __construct(
        public readonly SpanInterface $span,
        private readonly ?self $parent,
        private readonly OtelSpanInterface $enclosingSpan,
        private readonly string $activeSpanId,
    ) {}

    /**
     * @param ContextInterface $outer The context the trace starts in.
     * @param ContextInterface $own The context the trace activates, without the trace in it yet.
     */
    public static function start(SpanInterface $span, ContextInterface $outer, ContextInterface $own): self
    {
        return new self(
            $span,
            self::fromContext($outer),
            OtelSpan::fromContext($outer),
            OtelSpan::fromContext($own)->getContext()->getSpanId(),
        );
    }

    /**
     * The innermost thing the code runs in: a running trace, or else the OTel span innermost once the
     * traces that have ended are left out. A context can outlive its trace when it is captured and
     * activated later, or left attached by the trace's callback.
     */
    public static function innermostIn(ContextInterface $context): self|OtelSpanInterface
    {
        $active = OtelSpan::fromContext($context);
        for ($trace = self::fromContext($context); $trace !== null; $trace = $trace->parent) {
            if ($active->getContext()->getSpanId() !== $trace->activeSpanId) {
                break;
            }
            if (!$trace->ended) {
                return $trace;
            }
            $active = $trace->enclosingSpan;
        }

        return $active;
    }

    public function storeInContext(ContextInterface $context): ContextInterface
    {
        return $context->with(self::key(), $this);
    }

    public function end(): void
    {
        $this->ended = true;
    }

    private static function fromContext(ContextInterface $context): ?self
    {
        return $context->get(self::key());
    }

    /**
     * @return ContextKeyInterface<self>
     */
    private static function key(): ContextKeyInterface
    {
        if (self::$key === null) {
            /** @var ContextKeyInterface<self> $key */
            $key = Context::createKey(self::class);
            self::$key = $key;
        }

        return self::$key;
    }
}
