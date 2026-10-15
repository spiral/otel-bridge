<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs the tracer against the real OpenTelemetry SDK and checks the exported spans.
 */
#[Test]
#[Covers(Tracer::class)]
final class TracerExportTest
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const PARENT_SPAN_ID = 'b7ad6b7169203331';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::PARENT_SPAN_ID . '-01';

    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createProvider(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    }

    #[DataSet(['error', StatusCode::STATUS_ERROR], 'error')]
    #[DataSet(['ERROR', StatusCode::STATUS_ERROR], 'case-insensitive')]
    #[DataSet(['unset', StatusCode::STATUS_UNSET], 'unset')]
    #[DataSet(['ok', StatusCode::STATUS_OK], 'ok')]
    #[DataSet(['anything', StatusCode::STATUS_OK], 'unknown string')]
    #[DataSet([500, StatusCode::STATUS_OK], 'integer')]
    public function statusCodeIsMapped(string|int $code, string $expected): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setStatus($code, 'reason'));

        Assert::same($span->getStatus()->getCode(), $expected);
    }

    public function errorStatusKeepsDescription(): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setStatus('error', 'Broken pipe'));

        Assert::same($span->getStatus()->getDescription(), 'Broken pipe');
    }

    public function statusStaysUnsetWhenNotSet(): void
    {
        $span = $this->traceOne(static fn() => null);

        Assert::same($span->getStatus()->getCode(), StatusCode::STATUS_UNSET);
    }

    public function attributeValuesAreNormalized(): void
    {
        $span = $this->traceOne(static function (SpanInterface $span): void {
            $span->setAttribute('object', new \stdClass());
            $span->setAttribute('stringable', new class implements \Stringable {
                public function __toString(): string
                {
                    return 'text';
                }
            });
            $span->setAttribute('json', new class implements \JsonSerializable {
                public function jsonSerialize(): array
                {
                    return ['a' => 1];
                }
            });
        });

        $attributes = $span->getAttributes()->toArray();
        Assert::same($attributes['object'], \stdClass::class);
        Assert::same($attributes['stringable'], 'text');
        Assert::same($attributes['json'], '{"a":1}');
    }

    public function resourceAttributeIsDescribedByType(): void
    {
        $resource = \fopen('php://memory', 'rb');
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setAttribute('resource', $resource));
        \fclose($resource);

        Assert::same($span->getAttributes()->toArray()['resource'], 'resource (stream)');
    }

    #[DataSet([null, SpanKind::KIND_INTERNAL], 'default')]
    #[DataSet([TraceKind::INTERNAL, SpanKind::KIND_INTERNAL], 'internal')]
    #[DataSet([TraceKind::CLIENT, SpanKind::KIND_CLIENT], 'client')]
    #[DataSet([TraceKind::SERVER, SpanKind::KIND_SERVER], 'server')]
    #[DataSet([TraceKind::PRODUCER, SpanKind::KIND_PRODUCER], 'producer')]
    #[DataSet([TraceKind::CONSUMER, SpanKind::KIND_CONSUMER], 'consumer')]
    public function traceKindIsMapped(?TraceKind $traceKind, int $expected): void
    {
        $this->createTracer()->trace('test', static fn() => null, traceKind: $traceKind);

        Assert::same($this->exporter->getSpans()[0]->getKind(), $expected);
    }

    public function startTimeIsKept(): void
    {
        $this->createTracer()->trace('test', static fn() => null, startTime: 1_700_000_000_000_000_000);

        Assert::same($this->exporter->getSpans()[0]->getStartEpochNanos(), 1_700_000_000_000_000_000);
    }

    public function exceptionIsRethrown(): never
    {
        $tracer = $this->createTracer();

        Expect::exception(\RuntimeException::class)->withMessage('Boom');

        $tracer->trace('test', static fn() => throw new \RuntimeException('Boom'));
    }

    public function exceptionIsRecordedAsEvent(): void
    {
        try {
            $this->createTracer()->trace('test', static fn() => throw new \RuntimeException('Boom'));
        } catch (\RuntimeException) {
            # Rethrowing is checked by exceptionIsRethrown(); this test only needs the span.
        }

        $events = $this->exporter->getSpans()[0]->getEvents();
        Assert::count($events, 1);
        Assert::same($events[0]->getName(), 'exception');
    }

    public function traceStateIsPropagated(): void
    {
        $tracer = $this->createTracer([
            'traceparent' => self::TRACEPARENT,
            'tracestate' => 'vendor=value',
        ]);

        $tracer->trace('test', static fn() => null);

        Assert::same($tracer->getContext()['tracestate'] ?? null, 'vendor=value');
    }

    public function severalTraceStateFieldsAreCombined(): void
    {
        $tracer = $this->createTracer([
            'traceparent' => [self::TRACEPARENT],
            'tracestate' => ['a=1', 'b=2'],
        ]);

        $tracer->trace('test', static fn() => null);

        Assert::same((string) $this->exporter->getSpans()[0]->getContext()->getTraceState(), 'a=1,b=2');
        Assert::same($tracer->getContext()['tracestate'] ?? null, 'a=1,b=2');
    }

    /**
     * W3C Trace Context: a tracestate with an invalid list-member or more than 32 members is discarded whole,
     * also when the offending member comes from a later header field.
     */
    #[DataSet([['a=1', 'b']], 'invalid member in a later field')]
    #[DataSet([['m0=0,m1=1,m2=2,m3=3,m4=4,m5=5,m6=6,m7=7,m8=8,m9=9,m10=10,m11=11,m12=12,m13=13,m14=14,m15=15', 'm16=16,m17=17,m18=18,m19=19,m20=20,m21=21,m22=22,m23=23,m24=24,m25=25,m26=26,m27=27,m28=28,m29=29,m30=30,m31=31', 'm32=32']], '33 members over three fields')]
    public function invalidCombinedTraceStateIsDiscarded(array $tracestate): void
    {
        $tracer = $this->createTracer([
            'traceparent' => [self::TRACEPARENT],
            'tracestate' => $tracestate,
        ]);

        $tracer->trace('test', static fn() => null);

        Assert::same((string) $this->exporter->getSpans()[0]->getContext()->getTraceState(), '');
        Assert::false(isset($tracer->getContext()['tracestate']));
    }

    public function propagatedContextBecomesParent(): void
    {
        $span = $this->traceOne(
            static fn() => null,
            context: ['traceparent' => self::TRACEPARENT],
        );

        Assert::same($span->getTraceId(), self::TRACE_ID);
        Assert::same($span->getParentSpanId(), self::PARENT_SPAN_ID);
    }

    public function contextIsReturnedUntilFirstTrace(): void
    {
        $context = ['traceparent' => self::TRACEPARENT];

        Assert::same($this->createTracer($context)->getContext(), $context);
    }

    public function contextPointsToLastSpanAfterTrace(): void
    {
        $tracer = $this->createTracer();

        $tracer->trace('test', static fn() => null);

        $span = $this->exporter->getSpans()[0];
        Assert::same(
            $tracer->getContext(),
            ['traceparent' => '00-' . $span->getTraceId() . '-' . $span->getSpanId() . '-01'],
        );
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

    private function traceOne(\Closure $callback, array $context = []): SpanDataInterface
    {
        $this->createTracer($context)->trace('test', $callback);

        $spans = $this->exporter->getSpans();
        Assert::count($spans, 1);

        return $spans[0];
    }
}
