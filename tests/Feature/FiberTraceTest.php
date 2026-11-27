<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Span\OtelCurrentSpanProvider;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\CurrentSpan;
use Spiral\Telemetry\SpanInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A trace run inside a fiber: the framework runs the callback in a fiber of its own, which OTel gives no
 * context, so the code there must see the trace's OTel context all the same.
 */
#[Test]
#[Covers(Tracer::class)]
final class FiberTraceTest
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;
    private Tracer $tracer;

    #[BeforeTest]
    public function createTracer(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracer = new Tracer(
            new Container(),
            ($this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter)))->getTracer('test'),
            TraceContextPropagator::getInstance(),
        );
        CurrentSpan::setProvider(new OtelCurrentSpanProvider());
    }

    #[AfterTest]
    public function unsetProvider(): void
    {
        CurrentSpan::setProvider(null);
    }

    #[DataSet([false], 'without suspension')]
    #[DataSet([true], 'across suspension')]
    public function otelSpanOfTraceIsActive(bool $suspend): void
    {
        $active = $this->inFiber(fn(): string => $this->tracer->trace('trace', static function () use ($suspend): string {
            $suspend and \Fiber::suspend();
            return OtelSpan::getCurrent()->getContext()->getSpanId();
        }, scoped: true));

        Assert::same($active, $this->span('trace')->getSpanId());
    }

    /**
     * As a hook starts its span: with the active span for the parent.
     */
    #[DataSet([false], 'without suspension')]
    #[DataSet([true], 'across suspension')]
    public function spanStartedInsideIsChildOfTrace(bool $suspend): void
    {
        $this->inFiber(fn() => $this->tracer->trace('trace', function () use ($suspend): void {
            $suspend and \Fiber::suspend();
            $this->provider->getTracer('hook')->spanBuilder('hook')->startSpan()->end();
        }, scoped: true));

        Assert::same($this->span('hook')->getParentSpanId(), $this->span('trace')->getSpanId());
        Assert::same($this->span('hook')->getTraceId(), $this->span('trace')->getTraceId());
    }

    #[DataSet([false], 'without suspension')]
    #[DataSet([true], 'across suspension')]
    public function contextCarriesSpanOfTrace(bool $suspend): void
    {
        $tracer = $this->tracer;
        $headers = $this->inFiber(static fn(): array => $tracer->trace('trace', static function () use ($tracer, $suspend): array {
            $suspend and \Fiber::suspend();
            return $tracer->getContext();
        }, scoped: true));

        Assert::string($headers['traceparent'] ?? '')->contains('-' . $this->span('trace')->getSpanId() . '-');
    }

    #[DataSet([false, false], 'not scoped')]
    #[DataSet([true, false], 'scoped')]
    #[DataSet([true, true], 'scoped, across suspension')]
    public function baggageActivatedAroundTraceIsCurrent(bool $scoped, bool $suspend): void
    {
        $tenant = $this->inFiber(function () use ($scoped, $suspend): ?string {
            $scope = Baggage::getBuilder()->set('tenant', 'acme')->build()->activate();
            try {
                return $this->tracer->trace('trace', static function () use ($suspend): mixed {
                    $suspend and \Fiber::suspend();
                    return Baggage::getCurrent()->getValue('tenant');
                }, scoped: $scoped);
            } finally {
                $scope->detach();
            }
        });

        Assert::same($tenant, 'acme');
    }

    #[DataSet([false, false], 'not scoped')]
    #[DataSet([true, false], 'scoped')]
    #[DataSet([true, true], 'scoped, across suspension')]
    public function callbackGetsItsArgumentsAndReturnsItsValue(bool $scoped, bool $suspend): void
    {
        $result = $this->inFiber(fn(): string => $this->tracer->trace('trace', static function (SpanInterface $span) use ($suspend): string {
            $suspend and \Fiber::suspend();
            Assert::same(CurrentSpan::get(), $span);
            $span->setAttribute('in', 'fiber');
            return 'result';
        }, scoped: $scoped));

        Assert::same($result, 'result');
        Assert::same($this->span('trace')->getAttributes()->toArray(), ['in' => 'fiber']);
    }

    public function exceptionPassesThroughAcrossSuspension(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('Broken');

        $this->inFiber(fn() => $this->tracer->trace('trace', static function (): never {
            \Fiber::suspend();
            throw new \RuntimeException('Broken');
        }, scoped: true));
    }

    #[DataSet([false], 'without suspension')]
    #[DataSet([true], 'across suspension')]
    public function nestedTraceIsChildOfOuterAndLeavesItActive(bool $suspend): void
    {
        $active = $this->inFiber(fn(): string => $this->tracer->trace('outer', function () use ($suspend): string {
            $this->tracer->trace('nested', static function () use ($suspend): void {
                $suspend and \Fiber::suspend();
            }, scoped: true);
            $suspend and \Fiber::suspend();
            return OtelSpan::getCurrent()->getContext()->getSpanId();
        }, scoped: true));

        Assert::same($this->span('nested')->getParentSpanId(), $this->span('outer')->getSpanId());
        Assert::same($active, $this->span('outer')->getSpanId());
    }

    /**
     * Runs the callback in a fiber, resuming it until it ends, and checks at each suspension that the
     * fiber's context does not show outside it.
     */
    private function inFiber(\Closure $callback): mixed
    {
        $fiber = new \Fiber(static function () use ($callback): mixed {
            # Unless OTEL_PHP_FIBERS_ENABLED forks the context over FFI, a fiber has none until one is attached.
            $scope = Context::getRoot()->activate();
            try {
                return $callback();
            } finally {
                $scope->detach();
            }
        });

        $fiber->start();
        while (!$fiber->isTerminated()) {
            Assert::false(OtelSpan::getCurrent()->getContext()->isValid());
            $fiber->resume();
        }

        return $fiber->getReturn();
    }

    private function span(string $name): SpanDataInterface
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        throw new \LogicException("No span named {$name} was exported.");
    }
}
