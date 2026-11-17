<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Span;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use Spiral\Telemetry\SpanInterface;

/**
 * A running trace of {@see \Spiral\OpenTelemetry\Tracer}, kept in the OTel context for
 * {@see \Spiral\OpenTelemetry\CurrentSpan}.
 *
 * @internal
 */
final class ActiveTrace
{
    /** @var ContextKeyInterface<self>|null */
    private static ?ContextKeyInterface $key = null;

    private bool $ended = false;

    /**
     * @param string $activeSpanId The id of the OTel span active in the trace's own context: the trace's
     *        span when it is scoped, the enclosing one otherwise, the invalid id with none. A span with
     *        another id active later was activated inside the trace. By id, as a suppressed span is a new
     *        non-recording wrapper of its parent's span context; for the same reason only a wrapper of this
     *        span is recognized, not one of a span activated inside the trace. The invalid id also matches
     *        on purpose: anything with it inside the trace, like `Span::getInvalid()`, means no span.
     */
    private function __construct(
        public readonly SpanInterface $span,
        private readonly string $activeSpanId,
    ) {}

    /**
     * @param ContextInterface $context Holds the OTel span the trace makes active, if any.
     */
    public static function start(SpanInterface $span, ContextInterface $context): self
    {
        return new self($span, OtelSpan::fromContext($context)->getContext()->getSpanId());
    }

    public static function fromContext(ContextInterface $context): ?self
    {
        return $context->get(self::key());
    }

    public function storeInContext(ContextInterface $context): ContextInterface
    {
        return $context->with(self::key(), $this);
    }

    /**
     * A context can outlive the trace: one captured and activated later, or one left attached.
     */
    public function end(): void
    {
        $this->ended = true;
    }

    /**
     * Whether the trace is running and its span is the innermost one in the context.
     */
    public function isInnermostIn(ContextInterface $context): bool
    {
        return !$this->ended && OtelSpan::fromContext($context)->getContext()->getSpanId() === $this->activeSpanId;
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
