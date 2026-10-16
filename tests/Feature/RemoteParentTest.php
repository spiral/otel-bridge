<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Tracer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A tracer made from incoming headers parents a span by the remote span only while no local span is active.
 */
#[Test]
#[Covers(Tracer::class)]
final class RemoteParentTest
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const REMOTE_SPAN_ID = 'b7ad6b7169203331';
    private const INCOMING = ['traceparent' => '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01'];

    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createProvider(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    }

    public function nestedScopedSpansFormTree(): void
    {
        $tracer = $this->createTracer(self::INCOMING);

        $tracer->trace('outer', static fn() => $tracer->trace(
            'inner',
            static fn() => $tracer->trace('leaf', static fn() => null, scoped: true),
            scoped: true,
        ), scoped: true);

        $outer = $this->span('outer');
        Assert::same($outer->getTraceId(), self::TRACE_ID);
        Assert::same($outer->getParentSpanId(), self::REMOTE_SPAN_ID);
        Assert::same($this->span('inner')->getTraceId(), self::TRACE_ID);
        Assert::same($this->span('inner')->getParentSpanId(), $outer->getSpanId());
        Assert::same($this->span('leaf')->getParentSpanId(), $this->span('inner')->getSpanId());
    }

    public function nonScopedOuterSpanLeavesChildrenUnderRemoteParent(): void
    {
        $tracer = $this->createTracer(self::INCOMING);

        $tracer->trace('outer', static fn() => $tracer->trace('inner', static fn() => null, scoped: true));

        Assert::same($this->span('outer')->getParentSpanId(), self::REMOTE_SPAN_ID);
        Assert::same($this->span('inner')->getParentSpanId(), self::REMOTE_SPAN_ID);
    }

    public function sequentialRootSpansHangOffRemoteParent(): void
    {
        $tracer = $this->createTracer(self::INCOMING);

        $tracer->trace('first', static fn() => null, scoped: true);
        $tracer->trace('second', static fn() => null, scoped: true);

        Assert::same($this->span('first')->getParentSpanId(), self::REMOTE_SPAN_ID);
        Assert::same($this->span('second')->getParentSpanId(), self::REMOTE_SPAN_ID);
    }

    public function activeSpanTakesPrecedenceOverIncomingContext(): void
    {
        $external = $this->provider->getTracer('external')->spanBuilder('external')->startSpan();
        $scope = $external->activate();
        try {
            $this->createTracer(self::INCOMING)->trace('outer', static fn() => null, scoped: true);
        } finally {
            $scope->detach();
            $external->end();
        }

        $outer = $this->span('outer');
        Assert::same($outer->getTraceId(), $external->getContext()->getTraceId());
        Assert::same($outer->getParentSpanId(), $external->getContext()->getSpanId());
    }

    public function nestedSpansWithoutIncomingContextFormTree(): void
    {
        $tracer = $this->createTracer();

        $tracer->trace('outer', static fn() => $tracer->trace('inner', static fn() => null, scoped: true), scoped: true);

        $outer = $this->span('outer');
        Assert::false($outer->getParentContext()->isValid());
        Assert::same($this->span('inner')->getTraceId(), $outer->getTraceId());
        Assert::same($this->span('inner')->getParentSpanId(), $outer->getSpanId());
    }

    private function createTracer(array $context = []): Tracer
    {
        return new Tracer(
            new Container(),
            $this->provider->getTracer('test'),
            TraceContextPropagator::getInstance(),
            $context,
        );
    }

    private function span(string $name): SpanDataInterface
    {
        $spans = \array_values(\array_filter(
            $this->exporter->getSpans(),
            static fn(SpanDataInterface $span): bool => $span->getName() === $name,
        ));
        Assert::count($spans, 1);

        return $spans[0];
    }
}
