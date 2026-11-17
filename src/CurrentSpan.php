<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
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
 * It is the span of the innermost trace started by {@see Tracer::trace()}, scoped or not, unless an OTel
 * span was activated inside that trace, as `#[WithSpan]` and hooks do: then it is that span. Values set
 * on it are converted as for the span passed to the traced callback. Outside a trace and an active span,
 * it is a {@see NoopSpan} that drops everything.
 *
 * The span is found in the OTel context, so a fiber sees the trace it runs in once it has a context of
 * its own; until then, OTel falls back to the context of the main thread.
 */
final class CurrentSpan
{
    /** @var ContextKeyInterface<ActiveTrace>|null */
    private static ?ContextKeyInterface $key = null;

    private function __construct() {}

    public static function get(): SpanInterface
    {
        $context = Context::getCurrent();
        $trace = $context->get(self::key());
        $active = OtelSpan::fromContext($context);

        return match (true) {
            $trace !== null && $active === $trace->activeSpan => $trace->span,
            $active instanceof ReadWriteSpanInterface && !$active->hasEnded() => new OtelSpanAdapter($active),
            default => NoopSpan::instance(),
        };
    }

    /**
     * @internal For {@see Tracer}: the context must already hold the OTel span the trace makes active.
     */
    public static function storeInContext(SpanInterface $span, ContextInterface $context): ContextInterface
    {
        return $context->with(self::key(), new ActiveTrace($span, OtelSpan::fromContext($context)));
    }

    /**
     * @return ContextKeyInterface<ActiveTrace>
     */
    private static function key(): ContextKeyInterface
    {
        if (self::$key === null) {
            /** @var ContextKeyInterface<ActiveTrace> $key */
            $key = Context::createKey(ActiveTrace::class);
            self::$key = $key;
        }

        return self::$key;
    }
}
