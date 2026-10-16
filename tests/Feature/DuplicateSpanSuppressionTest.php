<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Trace\DuplicateSpanSuppressionStrategy;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The spans of `opentelemetry-auto-psr15` around a Spiral request, made here with a tracer of its
 * instrumentation scope, the way its hooks make them.
 */
#[Test]
#[Covers(DuplicateSpanSuppressionStrategy::class)]
final class DuplicateSpanSuppressionTest
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createProvider(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(
            new SimpleSpanProcessor($this->exporter),
            spanSuppressionStrategy: new DuplicateSpanSuppressionStrategy(),
        );
    }

    public function serverSpanOfAppInsidePsr15ServerSpanIsSuppressed(): void
    {
        $tracer = $this->tracer();

        $this->inSpan($this->psr15(), 'GET', SpanKind::KIND_SERVER, static fn() => $tracer->trace(
            'GET /ping',
            static fn() => $tracer->trace('handler', static fn() => null, scoped: true),
            scoped: true,
            traceKind: TraceKind::SERVER,
        ));

        [$handler, $server] = $this->exported();
        Assert::same($server->getName(), 'GET');
        Assert::same($handler->getName(), 'handler');
        Assert::same($handler->getParentSpanId(), $server->getSpanId());
    }

    /**
     * Spiral traces its middleware itself; psr15 spans of handlers and middleware would double them.
     */
    public function internalSpanOfPsr15IsSuppressed(): void
    {
        $tracer = $this->tracer();
        $psr15 = $this->psr15();

        $this->inSpan($psr15, 'GET', SpanKind::KIND_SERVER, fn() => $tracer->trace(
            'Middleware processing',
            fn() => $this->inSpan($psr15, 'Middleware::process', SpanKind::KIND_INTERNAL, static fn() => $tracer->trace(
                'handler',
                static fn() => null,
            )),
            scoped: true,
        ));

        [$handler, $middleware, $server] = $this->exported();
        Assert::same($middleware->getName(), 'Middleware processing');
        Assert::same($handler->getParentSpanId(), $middleware->getSpanId());
        Assert::same($middleware->getParentSpanId(), $server->getSpanId());
    }

    #[DataSet([SpanKind::KIND_CLIENT, TraceKind::CLIENT], 'client')]
    #[DataSet([SpanKind::KIND_PRODUCER, TraceKind::PRODUCER], 'producer')]
    #[DataSet([SpanKind::KIND_CONSUMER, TraceKind::CONSUMER], 'consumer')]
    public function spanOfOtherScopeInsideSpanOfSameKindIsSuppressed(int $kind, TraceKind $traceKind): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', fn() => $this->inSpan(
            $this->provider->getTracer('hook'),
            'inner',
            $kind,
            static fn() => null,
        ), scoped: true, traceKind: $traceKind);

        Assert::same(\array_map(static fn(SpanDataInterface $s): string => $s->getName(), $this->exported()), ['outer']);
    }

    /**
     * Suppression goes by the kind of the outer span, whatever lies between.
     */
    public function spanInsideSpanOfSameKindIsSuppressedAtAnyDepth(): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', static fn() => $tracer->trace(
            'between',
            static fn() => $tracer->trace('inner', static fn() => null, scoped: true, traceKind: TraceKind::CLIENT),
            scoped: true,
        ), scoped: true, traceKind: TraceKind::CLIENT);

        Assert::same(\array_map(static fn(SpanDataInterface $s): string => $s->getName(), $this->exported()), ['between', 'outer']);
    }

    public function internalSpanOfOtherScopeIsKept(): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', fn() => $this->inSpan(
            $this->provider->getTracer('hook'),
            'inner',
            SpanKind::KIND_INTERNAL,
            static fn() => null,
        ), scoped: true);

        [$inner, $outer] = $this->exported();
        Assert::same($inner->getParentSpanId(), $outer->getSpanId());
    }

    public function serverSpanOfPsr15WithoutParentIsKept(): void
    {
        $this->inSpan($this->psr15(), 'GET', SpanKind::KIND_SERVER, static fn() => null);

        Assert::same($this->exported()[0]->getName(), 'GET');
    }

    private function inSpan(TracerInterface $tracer, string $name, int $kind, \Closure $body): void
    {
        $span = $tracer->spanBuilder($name)->setSpanKind($kind)->startSpan();
        $scope = $span->activate();
        try {
            $body();
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function psr15(): TracerInterface
    {
        return $this->provider->getTracer(DuplicateSpanSuppressionStrategy::PSR15_SCOPE);
    }

    private function tracer(): Tracer
    {
        return new Tracer(new Container(), $this->provider->getTracer('spiral/otel-bridge'), TraceContextPropagator::getInstance());
    }

    /**
     * @return list<SpanDataInterface>
     */
    private function exported(): array
    {
        return $this->exporter->getSpans();
    }
}
