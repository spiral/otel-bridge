<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\CurrentSpan;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\AttributePriority;
use Spiral\OpenTelemetry\Span\NoopSpan;
use Spiral\OpenTelemetry\Span\OtelSpanAdapter;
use Spiral\OpenTelemetry\Span\SpanConverter;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\SpanInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(CurrentSpan::class)]
#[Covers(NoopSpan::class)]
#[Covers(OtelSpanAdapter::class)]
#[Covers(SpanConverter::class)]
final class CurrentSpanTest
{
    private InMemoryExporter $exporter;
    private Tracer $tracer;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createTracer(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracer = new Tracer(
            new Container(),
            ($this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter)))->getTracer('test'),
            TraceContextPropagator::getInstance(),
        );
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isTheSpanOfTheTrace(bool $scoped): void
    {
        $this->tracer->trace('test', static function (SpanInterface $span): void {
            Assert::same(CurrentSpan::get(), $span);
            CurrentSpan::get()->setAttribute('count', 3);
        }, scoped: $scoped);

        Assert::same($this->span('test')->getAttributes()->toArray(), ['count' => 3]);
    }

    public function attributesAreNormalized(): void
    {
        $this->tracer->trace('test', static fn() => CurrentSpan::get()->setAttribute('priority', AttributePriority::High));

        Assert::same($this->span('test')->getAttributes()->toArray(), ['priority' => AttributePriority::High->value]);
    }

    public function nestedTraceShadowsOuterUntilItEnds(): void
    {
        $this->tracer->trace('outer', function (): void {
            $this->tracer->trace('inner', static fn() => CurrentSpan::get()->setAttribute('in', 'inner'));
            CurrentSpan::get()->setAttribute('in', 'outer');
        });

        Assert::same($this->span('inner')->getAttributes()->toArray(), ['in' => 'inner']);
        Assert::same($this->span('outer')->getAttributes()->toArray(), ['in' => 'outer']);
    }

    public function isRestoredWhenTraceThrows(): void
    {
        try {
            $this->tracer->trace('test', static fn() => throw new \RuntimeException('Broken'));
        } catch (\RuntimeException) {
        }

        Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
    }

    public function isNoopOutsideTrace(): void
    {
        $span = CurrentSpan::get();
        $span->setAttribute('count', 3)->setAttributes(['a' => 1])->setStatus('error')->updateName('renamed');

        Assert::instanceOf($span, NoopSpan::class);
        Assert::same($span->getName(), 'noop');
        Assert::same($span->getAttributes(), []);
        Assert::false($span->hasAttribute('count'));
        Assert::null($span->getAttribute('count'));
        Assert::null($span->getStatus());
        Assert::same($this->exporter->getSpans(), []);
    }

    public function traceNotScopedLeavesOtelSpanInactive(): void
    {
        $this->tracer->trace('test', static fn() => Assert::false(OtelSpan::getCurrent()->getContext()->isValid()));
    }

    public function fiberSeesItsOwnTrace(): void
    {
        $fiber = new \Fiber(function (): void {
            # Unless OTEL_PHP_FIBERS_ENABLED forks the context over FFI, a fiber has none until one is attached.
            $scope = Context::getRoot()->activate();
            $this->tracer->trace('fiber', static function (): void {
                \Fiber::suspend();
                CurrentSpan::get()->setAttribute('in', 'fiber');
            });
            $scope->detach();
        });

        $this->tracer->trace('main', static function () use ($fiber): void {
            $fiber->start();
            CurrentSpan::get()->setAttribute('in', 'main');
            $fiber->resume();
        });

        Assert::same($this->span('fiber')->getAttributes()->toArray(), ['in' => 'fiber']);
        Assert::same($this->span('main')->getAttributes()->toArray(), ['in' => 'main']);
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isSpanActivatedInsideTraceUntilItIsDetached(bool $scoped): void
    {
        $this->tracer->trace('trace', function (): void {
            $this->inSpan('inner', static fn() => CurrentSpan::get()->setAttribute('in', 'inner'));
            CurrentSpan::get()->setAttribute('after', 'trace');
        }, scoped: $scoped);

        Assert::same($this->span('inner')->getAttributes()->toArray(), ['in' => 'inner']);
        Assert::same($this->span('trace')->getAttributes()->toArray(), ['after' => 'trace']);
    }

    public function isSpanActiveOutsideTrace(): void
    {
        $this->inSpan('outer', static fn() => CurrentSpan::get()->setAttribute('in', 'outer'));

        Assert::same($this->span('outer')->getAttributes()->toArray(), ['in' => 'outer']);
    }

    public function activeSpanGetsConvertedValuesAtOnce(): void
    {
        $this->inSpan('test', static function (): void {
            $span = CurrentSpan::get()
                ->updateName('renamed')
                ->setAttribute('priority', AttributePriority::High)
                ->setAttributes(['list' => [1, null], 'map' => ['a' => 1]]);

            Assert::instanceOf($span, OtelSpanAdapter::class);
            Assert::same($span->getName(), 'renamed');
            Assert::same($span->getAttribute('priority'), AttributePriority::High->value);
            Assert::true($span->hasAttribute('priority'));
            Assert::false($span->hasAttribute('missing'));
            Assert::same($span->getAttributes(), ['priority' => 42, 'list' => [1, 0], 'map' => '{"a":1}']);
            Assert::null($span->getStatus());
        });

        Assert::same($this->span('renamed')->getAttributes()->count(), 3);
    }

    #[DataSet([SpanKind::KIND_SERVER, 404, null], '404 on server')]
    #[DataSet([SpanKind::KIND_INTERNAL, 404, StatusCode::STATUS_ERROR], '404 on internal')]
    #[DataSet([SpanKind::KIND_SERVER, 'ok', StatusCode::STATUS_OK], 'ok')]
    public function activeSpanStatusIsConvertedByKind(int $kind, int|string $code, ?string $expected): void
    {
        $this->inSpan('test', static function () use ($code, $expected): void {
            $status = CurrentSpan::get()->setStatus($code, 'reason')->getStatus();

            Assert::same($status?->code, $expected);
            Assert::same($status?->description, $expected === StatusCode::STATUS_ERROR ? 'reason' : null);
        }, $kind);
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isNoopInsideNonRecordingSpan(bool $scoped): void
    {
        $this->tracer->trace('trace', static function (): void {
            $unsampled = SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331');
            $scope = OtelSpan::wrap($unsampled)->activate();
            CurrentSpan::get()->setAttribute('in', 'unsampled');
            $scope->detach();
        }, scoped: $scoped);

        Assert::same($this->span('trace')->getAttributes()->toArray(), []);
    }

    public function isNoopInsideEndedSpan(): void
    {
        $span = $this->provider->getTracer('test')->spanBuilder('ended')->startSpan();
        $span->end();
        $scope = $span->activate();

        try {
            Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
        } finally {
            $scope->detach();
        }
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
    /**
     * Runs the callback in an OTel span started and activated by hand, as `#[WithSpan]` does.
     */
    private function inSpan(string $name, \Closure $callback, int $kind = SpanKind::KIND_INTERNAL): void
    {
        $span = $this->provider->getTracer('test')->spanBuilder($name)->setSpanKind($kind)->startSpan();
        $scope = $span->activate();
        try {
            $callback();
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
