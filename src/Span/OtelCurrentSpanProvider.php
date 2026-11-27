<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Span;

use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use Spiral\Telemetry\CurrentSpanProviderInterface;
use Spiral\Telemetry\Span\NoopSpan;
use Spiral\Telemetry\SpanInterface;

/**
 * Gives {@see \Spiral\Telemetry\CurrentSpan} the innermost span in the OTel context: the Spiral span of a
 * running trace of the bridge, or else a recording OTel span, like that of `#[WithSpan]` or a hook.
 *
 * A no-op span inside a span with nothing to record, as an unsampled one, where the span of the trace the
 * framework would fall back to is not where the code runs; null with no span at all, as in a fiber that
 * activated a context of its own, where the framework knows the trace better. It keeps no state, as the
 * OTel context is global, so one instance serves every tracer and the code outside traces.
 *
 * @internal
 */
final class OtelCurrentSpanProvider implements CurrentSpanProviderInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    #[\Override]
    public function getCurrentSpan(): ?SpanInterface
    {
        $innermost = ActiveTrace::innermostIn(Context::getCurrent());

        return match (true) {
            $innermost instanceof ActiveTrace => $innermost->span,
            !$innermost->getContext()->isValid() => null,
            $innermost instanceof ReadWriteSpanInterface && !$innermost->hasEnded() => new OtelSpanAdapter($innermost),
            default => NoopSpan::instance(),
        };
    }
}
