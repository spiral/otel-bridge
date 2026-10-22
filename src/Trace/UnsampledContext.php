<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManager;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\RandomIdGenerator;

/**
 * A context for the SDK to export spans under, in which auto-instrumentation records nothing.
 *
 * Auto-instrumentation of PSR-18 or curl traces the request the exporter sends, and that span would go
 * out with the next export, its export with the one after, and so on; with the simple processor this never
 * ends. The hooks of ext-opentelemetry cannot be switched off for a call, so the context gives them a
 * no-op tracer provider, disables the hooks that check it, and makes the active span valid but not sampled
 * for a tracer taken earlier and a parent-based sampler.
 *
 * Built over the root context: the processor keeps the context it is built in for every export, and the
 * context of the request that happens to build it must not stay there.
 *
 * @internal
 */
final class UnsampledContext
{
    public static function create(): ContextInterface
    {
        $ids = new RandomIdGenerator();
        /** @psalm-suppress InternalMethod The root, so nothing of the current request is kept */
        $context = Configurator::create()
            ->withTracerProvider(new NoopTracerProvider())
            ->storeInContext(Context::getRoot());

        return Span::wrap(SpanContext::create($ids->generateTraceId(), $ids->generateSpanId()))
            ->storeInContext(HookManager::disable($context));
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
