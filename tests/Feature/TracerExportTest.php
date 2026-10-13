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
use Testo\Data\DataSet;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs the tracer against the real OpenTelemetry SDK and checks the exported spans.
 */
#[Test]
final class TracerExportTest
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const PARENT_SPAN_ID = 'b7ad6b7169203331';

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

    public function exceptionIsRecordedAndRethrown(): void
    {
        $exception = new \RuntimeException('Boom');
        try {
            $this->createTracer()->trace('test', static fn() => throw $exception);
            Assert::fail('The exception is not rethrown.');
        } catch (\RuntimeException $e) {
            Assert::same($e, $exception);
        }

        $events = $this->exporter->getSpans()[0]->getEvents();
        Assert::count($events, 1);
        Assert::same($events[0]->getName(), 'exception');
    }

    public function traceStateIsPropagated(): void
    {
        $tracer = $this->createTracer([
            'traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_SPAN_ID . '-01',
            'tracestate' => 'vendor=value',
        ]);
        $tracer->trace('test', static fn() => null);

        Assert::same($tracer->getContext()['tracestate'] ?? null, 'vendor=value');
    }

    public function propagatedContextBecomesParent(): void
    {
        $span = $this->traceOne(
            static fn() => null,
            context: ['traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_SPAN_ID . '-01'],
        );

        Assert::same($span->getTraceId(), self::TRACE_ID);
        Assert::same($span->getParentSpanId(), self::PARENT_SPAN_ID);
    }

    public function contextIsReturnedUntilFirstTrace(): void
    {
        $context = ['traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_SPAN_ID . '-01'];

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
