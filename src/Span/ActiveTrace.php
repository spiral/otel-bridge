<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Span;

use OpenTelemetry\API\Trace\SpanInterface as OtelSpanInterface;
use Spiral\Telemetry\SpanInterface;

/**
 * What {@see \Spiral\OpenTelemetry\Tracer::trace()} puts in the context for
 * {@see \Spiral\OpenTelemetry\CurrentSpan}.
 *
 * @internal
 */
final class ActiveTrace
{
    /**
     * @param OtelSpanInterface $activeSpan The OTel span active when the trace was activated: the span of
     *        the trace when it is scoped, the enclosing one otherwise. Another span active later means a
     *        span was activated inside the trace.
     */
    public function __construct(
        public readonly SpanInterface $span,
        public readonly OtelSpanInterface $activeSpan,
    ) {}
}
