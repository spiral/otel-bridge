<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\RandomIdGenerator;

/**
 * A context whose active span is valid but not sampled, for the SDK to export spans under.
 *
 * Auto-instrumentation of PSR-18 or curl traces the request the exporter sends, and that span would go
 * out with the next export, its export with the one after, and so on. With a parent that is not sampled,
 * the parent-based sampler, the SDK default, drops them. The hooks of ext-opentelemetry cannot be
 * switched off for a call.
 *
 * @internal
 */
final class UnsampledContext
{
    /**
     * The current context with the not sampled span in it.
     */
    public static function create(): ContextInterface
    {
        $ids = new RandomIdGenerator();

        return Span::wrap(SpanContext::create($ids->generateTraceId(), $ids->generateSpanId()))
            ->storeInContext(Context::getCurrent());
    }

    /**
     * Runs `$call` with that context active.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    public static function run(\Closure $call): mixed
    {
        $scope = Context::storage()->attach(self::create());
        try {
            return $call();
        } finally {
            $scope->detach();
        }
    }
}
