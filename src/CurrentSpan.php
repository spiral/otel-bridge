<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use Spiral\OpenTelemetry\Span\ActiveTrace;
use Spiral\OpenTelemetry\Span\NoopSpan;
use Spiral\OpenTelemetry\Span\OtelSpanAdapter;
use Spiral\Telemetry\SpanInterface;

/**
 * The innermost span the code runs in, for code that has no span passed to it:
 *
 *     CurrentSpan::get()->setAttribute('dict.org_types.count', \count($types));
 *
 * It is the span of the innermost running trace started by {@see Tracer::trace()}, scoped or not, unless
 * an OTel span was activated inside that trace, as `#[WithSpan]` and hooks do: then it is that span.
 * Outside a running trace and a recording span, it is a {@see NoopSpan} that drops everything.
 *
 * The span is found in the OTel context. In a fiber that has no context of its own, reading the context
 * raises a warning, which the error handler of a Spiral app turns into an exception.
 */
final class CurrentSpan
{
    private function __construct() {}

    public static function get(): SpanInterface
    {
        $context = Context::getCurrent();
        $trace = ActiveTrace::fromContext($context);
        if ($trace?->isInnermostIn($context)) {
            return $trace->span;
        }

        $active = OtelSpan::fromContext($context);

        return $active instanceof ReadWriteSpanInterface && !$active->hasEnded()
            ? new OtelSpanAdapter($active)
            : NoopSpan::instance();
    }
}
