<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use OpenTelemetry\API\Trace\TracerInterface as OtelTracerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\BatchProcessorProbeBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\HttpBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * The batch span processor, the SDK default for `OTEL_PHP_TRACES_PROCESSOR`
 * (open-telemetry/sdk `Common/Configuration/Defaults.php`), queues ended spans and exports them on a
 * full batch, an elapsed schedule delay, ForceFlush or Shutdown:
 * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/sdk.md#batching-processor
 *
 * A request or job ends with `FinalizerInterface::finalize(false)` and the app with `finalize(true)`
 * (the kernel destructor), which is where the queue has to be flushed.
 */
#[Test]
#[Env('OTEL_PHP_TRACES_PROCESSOR', 'batch')]
final class BatchProcessorTest extends TestCase
{
    private const SERVER_SPAN = 'GET /ping';

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), HttpBootloader::class, BatchProcessorProbeBootloader::class];
    }

    public function endedSpanWaitsInQueueUntilFinalize(): void
    {
        $this->tracer()->trace('queued', static fn() => null);

        Assert::count($this->traces()->settled(), 0);
    }

    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function finalizeFlushesQueuedSpans(): void
    {
        $this->tracer()->trace('job', static fn() => null, attributes: ['job.id' => 7]);
        $before = $this->traces()->settled();

        $this->finalizer()->finalize(false);

        $span = $this->traces()->await(1, timeout: 3.0)->named('job');
        Assert::count($before, 0);
        Assert::same($span->attributes['job.id'] ?? null, 7);
    }

    /**
     * finalize(false) ends a request in a long-running worker: it has to be ForceFlush, not Shutdown,
     * which would drop every later span: https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/sdk.md#shutdown-1
     */
    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function tracingGoesOnAfterEachFinalize(): void
    {
        $this->tracer()->trace('first', static fn() => null);
        $this->finalizer()->finalize(false);
        $first = $this->traces()->await(1, timeout: 3.0);

        $this->tracer()->trace('second', static fn() => null);
        $held = $this->traces()->settled();
        $this->finalizer()->finalize(false);
        $this->tracer()->trace('third', static fn() => null);
        $this->finalizer()->finalize(false);

        $all = $this->traces()->await(3, timeout: 3.0);
        Assert::count($first, 1, "Received:\n{$first}");
        Assert::count($held, 1, "The second span is exported before its finalize. Received:\n{$held}");
        Assert::count($all, 3, "Received:\n{$all}");
    }

    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function finalizeKeepsTheTreeOfScopedSpans(): void
    {
        $tracer = $this->tracer();
        $tracer->trace('root', static fn() => $tracer->trace(
            'child',
            static fn() => $tracer->trace('grandchild', static fn() => null, scoped: true),
            scoped: true,
        ), scoped: true);

        $this->finalizer()->finalize(false);

        $spans = $this->traces()->await(3, timeout: 3.0);
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::true($spans->named('root')->isRoot());
        Assert::same($spans->named('child')->parentSpanId, $spans->named('root')->spanId);
        Assert::same($spans->named('grandchild')->parentSpanId, $spans->named('child')->spanId);
        Assert::same($spans->named('grandchild')->traceId, $spans->named('root')->traceId);
    }

    /**
     * FakeHttp calls finalize(false) after every request, as the RoadRunner HTTP dispatcher does.
     */
    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function eachRequestOfWorkerIsExportedWhenItEnds(): void
    {
        $this->fakeHttp()->get('/ping')->assertOk();
        $afterFirst = $this->traces()->await(2, timeout: 3.0);

        $this->fakeHttp()->get('/ping')->assertOk();
        $afterSecond = $this->traces()->await(4, timeout: 3.0);

        $servers = \array_values(\array_filter(
            $afterSecond->spans,
            static fn(ReceivedSpan $span): bool => $span->name === self::SERVER_SPAN,
        ));
        Assert::count($afterFirst, 2, "Received:\n{$afterFirst}");
        Assert::same($afterFirst->named(self::SERVER_SPAN)->kind, ReceivedSpan::KIND_SERVER);
        Assert::count($afterSecond, 4, "Received:\n{$afterSecond}");
        Assert::count($servers, 2);
        Assert::notSame($servers[0]->traceId, $servers[1]->traceId);
    }

    /**
     * The kernel destructor calls finalize(true); Shutdown has to export what is still queued:
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/sdk.md#shutdown-1
     */
    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function terminatingFinalizeExportsQueuedSpans(): void
    {
        $this->tracer()->trace('last words', static fn() => null);

        $this->finalizer()->finalize(true);

        $spans = $this->traces()->await(1, timeout: 3.0);
        Assert::count($spans, 1, "Received:\n{$spans}");
        Assert::same($spans->spans[0]->name, 'last words');
    }

    /**
     * Unset falls back to the SDK default, which is `batch`.
     */
    #[Env('OTEL_PHP_TRACES_PROCESSOR', '')]
    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function defaultProcessorExportsOnFinalize(): void
    {
        $this->tracer()->trace('by default', static fn() => null);

        $this->finalizer()->finalize(false);

        Assert::same($this->traces()->await(1, timeout: 3.0)->named('by default')->name, 'by default');
    }

    /**
     * OTEL_BSP_MAX_EXPORT_BATCH_SIZE exports a full batch as soon as it fills up:
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/configuration/sdk-environment-variables.md#batch-span-processor
     */
    #[Env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', '2')]
    #[Env('OTEL_BSP_MAX_QUEUE_SIZE', '4')]
    #[DataSet([1, 0], 'batch not full')]
    #[DataSet([2, 2], 'batch full')]
    #[DataSet([5, 4], 'two full batches and a remainder')]
    public function fullBatchIsExportedWithoutFinalize(int $spans, int $exported): void
    {
        for ($i = 1; $i <= $spans; ++$i) {
            $this->tracer()->trace("span {$i}", static fn() => null);
        }

        $received = $this->traces()->settled();
        Assert::count($received, $exported, "Received:\n{$received}");
    }

    #[Env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', '2')]
    #[Env('OTEL_BSP_MAX_QUEUE_SIZE', '4')]
    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function finalizeExportsRemainderOfPartialBatch(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->tracer()->trace("span {$i}", static fn() => null);
        }
        $beforeFinalize = $this->traces()->settled();

        $this->finalizer()->finalize(false);

        $spans = $this->traces()->await(5, timeout: 3.0);
        Assert::count($beforeFinalize, 4, "Received:\n{$beforeFinalize}");
        Assert::count($spans, 5, "Received:\n{$spans}");
        Assert::same($spans->named('span 5')->name, 'span 5');
    }

    /**
     * Ending a span exports the queue once OTEL_BSP_SCHEDULE_DELAY has passed since the first queued span.
     */
    #[Env('OTEL_BSP_SCHEDULE_DELAY', '1')]
    public function elapsedScheduleDelayExportsQueueWhenNextSpanEnds(): void
    {
        $this->tracer()->trace('early', static fn() => null);
        \usleep(50_000);
        $this->tracer()->trace('late', static fn() => null);

        $spans = $this->traces()->await(2, timeout: 3.0);
        Assert::count($spans, 2, "Received:\n{$spans}");
    }

    #[Env('OTEL_BSP_SCHEDULE_DELAY', '60000')]
    public function scheduleDelayNotElapsedKeepsQueue(): void
    {
        $this->tracer()->trace('early', static fn() => null);
        \usleep(50_000);
        $this->tracer()->trace('late', static fn() => null);

        Assert::count($this->traces()->settled(), 0);
    }

    /**
     * Three spans from three separately resolved tracers fill one batch of three only if they share
     * one processor.
     */
    #[Env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', '3')]
    public function tracersResolvedSeparatelyShareOneBatch(): void
    {
        $container = $this->getContainer();

        $this->tracer()->trace('spiral', static fn() => null);
        $container->get(OtelTracerInterface::class)->spanBuilder('otel')->startSpan()->end();
        $container->get(TracerProviderInterface::class)->getTracer('other-scope')->spanBuilder('provider')->startSpan()->end();

        $spans = $this->traces()->await(3, timeout: 3.0);
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($spans->named('provider')->scopeName, 'other-scope');
        Assert::same($spans->named('otel')->scopeName, $spans->named('spiral')->scopeName);
    }

    /**
     * ForceFlush on the provider the app hands out flushes the spans of the Spiral tracer too:
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/sdk.md#forceflush
     */
    public function providerForceFlushExportsSpansOfSpiralTracer(): void
    {
        $this->tracer()->trace('spiral', static fn() => null);

        $flushed = $this->getContainer()->get(TracerProviderInterface::class)->forceFlush();

        $spans = $this->traces()->await(1, timeout: 3.0);
        Assert::true($flushed);
        Assert::count($spans, 1, "Received:\n{$spans}");
    }

    /**
     * The provider is meant to be one per app: https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/api.md#tracerprovider
     */
    #[DataSet([TracerProviderInterface::class], 'tracer provider')]
    #[DataSet([OtelTracerInterface::class], 'OTel tracer')]
    #[DataSet([SpanExporterInterface::class], 'span exporter')]
    #[Skip('Known issue: the tracer provider, OTel tracer and span exporter are not singletons, see https://github.com/spiral/otel-bridge/issues/10')]
    public function tracingServiceIsSingleton(string $service): void
    {
        $container = $this->getContainer();

        $first = $container->get($service);
        $second = $container->get($service);

        Assert::same($first, $second);
    }

    /**
     * The counterpart of {@see finalizeDoesNotStartTracingThatWasNeverUsed()}: the probe does see the stack.
     */
    #[Env('BATCH_PROCESSOR_PROBE', '1')]
    public function tracingStartsTheStackOnFirstUse(): void
    {
        $this->tracer()->trace('used', static fn() => null);

        Assert::same($this->getContainer()->get(BatchProcessorProbeBootloader::class)->exportersCreated, 1);
    }

    #[Env('BATCH_PROCESSOR_PROBE', '1')]
    #[DataSet([false], 'request end')]
    #[DataSet([true], 'termination')]
    public function finalizeDoesNotStartTracingThatWasNeverUsed(bool $terminate): void
    {
        $this->finalizer()->finalize($terminate);
        $this->finalizer()->finalize($terminate);

        Assert::same($this->getContainer()->get(BatchProcessorProbeBootloader::class)->exportersCreated, 0);
        Assert::count($this->traces()->settled(), 0);
    }

    /**
     * An exporter that cannot be built must not break the request or the worker:
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/error-handling.md
     */
    #[Env('OTEL_TRACES_EXPORTER', 'no-such-exporter')]
    #[DataSet([false], 'request end')]
    #[DataSet([true], 'termination')]
    public function finalizeSurvivesUnknownExporter(bool $terminate): void
    {
        $this->tracer()->trace('lost', static fn() => null);

        $this->finalizer()->finalize($terminate);
        $this->finalizer()->finalize($terminate);
        $result = $this->tracer()->trace('still traced', static fn() => 'ok');

        Assert::same($result, 'ok');
    }

    #[Skip('Known issue: the batch span processor is never flushed, see https://github.com/spiral/otel-bridge/issues/10')]
    public function repeatedFinalizeExportsEachSpanOnce(): void
    {
        $this->tracer()->trace('once', static fn() => null);

        $this->finalizer()->finalize(false);
        $this->finalizer()->finalize(false);
        $this->finalizer()->finalize(true);
        $this->finalizer()->finalize(true);

        $spans = $this->traces()->await(1, timeout: 3.0);
        Assert::count($spans, 1, "Received:\n{$spans}");
    }

    private function finalizer(): FinalizerInterface
    {
        return $this->getContainer()->get(FinalizerInterface::class);
    }
}
