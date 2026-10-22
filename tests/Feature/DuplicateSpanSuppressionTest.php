<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanKindSuppressionStrategy\SpanKindSuppressionStrategy;
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
 * instrumentation scope and the attributes its hooks set before starting a span.
 */
#[Test]
#[Covers(DuplicateSpanSuppressionStrategy::class)]
final class DuplicateSpanSuppressionTest
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';

    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createProvider(): void
    {
        $this->useStrategy(new DuplicateSpanSuppressionStrategy());
    }

    /**
     * psr15 up to 1.1 names the class in `code.namespace`, from 1.2 in `code.function.name`.
     */
    #[DataSet([['code.function.name' => 'handle', 'code.namespace' => 'Spiral\Http\Http']], 'psr15 1.1')]
    #[DataSet([['code.function.name' => 'Spiral\Http\Http::handle']], 'psr15 1.2')]
    public function psr15ServerSpanOfHttpIsSuppressedAndSpiralOneTakesTheRemoteParent(array $attributes): void
    {
        $tracer = $this->tracer();
        $remote = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01',
        ]);

        $this->inSpan($this->psr15(), 'GET', SpanKind::KIND_SERVER, static fn() => $tracer->trace(
            'GET /ping',
            static fn() => null,
            scoped: true,
            traceKind: TraceKind::SERVER,
        ), $attributes, $remote);

        $spans = $this->exported();
        Assert::count($spans, 1);
        Assert::same($spans[0]->getName(), 'GET /ping');
        Assert::same($spans[0]->getTraceId(), self::TRACE_ID);
        Assert::same($spans[0]->getParentSpanId(), self::REMOTE_SPAN_ID);
        Assert::true($spans[0]->getParentContext()->isRemote());
    }

    #[DataSet([['code.function.name' => 'handle', 'code.namespace' => 'App\Handler']], 'psr15 1.1')]
    #[DataSet([['code.function.name' => 'App\Handler::handle']], 'psr15 1.2')]
    #[DataSet([[]], 'no code attributes')]
    public function psr15ServerSpanOfOtherHandlerIsKept(array $attributes): void
    {
        $this->inSpan($this->psr15(), 'GET', SpanKind::KIND_SERVER, static fn() => null, $attributes);

        Assert::same(self::names($this->exported()), ['GET']);
    }

    /**
     * Spiral traces its middleware itself; psr15 spans of handlers and middleware would double them.
     */
    public function internalSpanOfPsr15IsSuppressed(): void
    {
        $tracer = $this->tracer();
        $psr15 = $this->psr15();

        $tracer->trace('Middleware processing', fn() => $this->inSpan(
            $psr15,
            'Middleware::process',
            SpanKind::KIND_INTERNAL,
            static fn() => $tracer->trace('handler', static fn() => null),
        ), scoped: true);

        [$handler, $middleware] = $this->exported();
        Assert::same(self::names($this->exported()), ['handler', 'Middleware processing']);
        Assert::same($handler->getParentSpanId(), $middleware->getSpanId());
    }

    /**
     * Nothing else is suppressed: a request handled inside a request, a job run synchronously inside a job.
     */
    #[DataSet([TraceKind::SERVER], 'server in server')]
    #[DataSet([TraceKind::CONSUMER], 'consumer in consumer')]
    #[DataSet([TraceKind::CLIENT], 'client in client')]
    public function spanInsideSpanOfSameKindIsKept(TraceKind $kind): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', static fn() => $tracer->trace('inner', static fn() => null, scoped: true, traceKind: $kind), scoped: true, traceKind: $kind);

        [$inner, $outer] = $this->exported();
        Assert::same($inner->getParentSpanId(), $outer->getSpanId());
    }

    public function spanOfOtherScopeIsLeftToTheGivenStrategy(): void
    {
        $this->useStrategy(new DuplicateSpanSuppressionStrategy(new SpanKindSuppressionStrategy()));
        $tracer = $this->tracer();

        $tracer->trace('outer', fn() => $this->inSpan(
            $this->provider->getTracer('hook'),
            'inner',
            SpanKind::KIND_CLIENT,
            static fn() => null,
        ), scoped: true, traceKind: TraceKind::CLIENT);

        Assert::same(self::names($this->exported()), ['outer']);
    }

    /**
     * The suppressed psr15 SERVER span of `Spiral\Http\Http` marks no SERVER span as active, so suppression
     * by kind keeps the Spiral one inside it.
     */
    public function spiralServerSpanStaysWithSuppressionByKind(): void
    {
        $this->useStrategy(new DuplicateSpanSuppressionStrategy(new SpanKindSuppressionStrategy()));
        $tracer = $this->tracer();

        $this->inSpan($this->psr15(), 'GET', SpanKind::KIND_SERVER, static fn() => $tracer->trace(
            'GET /ping',
            static fn() => null,
            scoped: true,
            traceKind: TraceKind::SERVER,
        ), ['code.function.name' => 'Spiral\Http\Http::handle']);

        Assert::same(self::names($this->exported()), ['GET /ping']);
    }

    public function psr15ServerSpanOfOtherHandlerIsLeftToTheGivenStrategy(): void
    {
        $this->useStrategy(new DuplicateSpanSuppressionStrategy(new SpanKindSuppressionStrategy()));
        $tracer = $this->tracer();

        $tracer->trace('outer', fn() => $this->inSpan(
            $this->psr15(),
            'GET',
            SpanKind::KIND_SERVER,
            static fn() => null,
            ['code.function.name' => 'App\Handler::handle'],
        ), scoped: true, traceKind: TraceKind::SERVER);

        Assert::same(self::names($this->exported()), ['outer']);
    }

    /**
     * @param list<SpanDataInterface> $spans
     * @return list<string>
     */
    private static function names(array $spans): array
    {
        return \array_map(static fn(SpanDataInterface $s): string => $s->getName(), $spans);
    }

    private function useStrategy(DuplicateSpanSuppressionStrategy $strategy): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter), spanSuppressionStrategy: $strategy);
    }

    /**
     * Runs `$body` inside a span made the way the hooks of psr15 make it: attributes set before the start,
     * the span stored over its parent.
     *
     * @param array<non-empty-string, string> $attributes
     */
    private function inSpan(
        TracerInterface $tracer,
        string $name,
        int $kind,
        \Closure $body,
        array $attributes = [],
        ?\OpenTelemetry\Context\ContextInterface $parent = null,
    ): void {
        $builder = $tracer->spanBuilder($name)->setSpanKind($kind)->setAttributes($attributes);
        $parent === null or $builder->setParent($parent);
        $span = $builder->startSpan();
        $scope = Context::storage()->attach($span->storeInContext($parent ?? Context::getCurrent()));
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
