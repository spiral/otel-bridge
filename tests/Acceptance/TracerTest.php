<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\Boot\EnvironmentInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerInterface;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * Traces made through Spiral's TracerInterface, exported over OTLP/HTTP and read back from Trap.
 */
#[Test]
final class TracerTest extends TestCase
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const REMOTE_SPAN_ID = 'b7ad6b7169203331';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01';

    public function traceArrivesWithServiceNameKindAttributesAndStatus(): void
    {
        $result = $this->tracer()->trace(
            'checkout',
            static function (SpanInterface $span): string {
                $span->setAttribute('order.id', 42)->setStatus('ok');
                return 'done';
            },
            attributes: ['user.role' => 'admin'],
            traceKind: TraceKind::SERVER,
        );

        $span = $this->traces()->await(1)->named('checkout');
        $serviceName = $this->getContainer()->get(EnvironmentInterface::class)->get('OTEL_SERVICE_NAME');
        Assert::same($result, 'done');
        Assert::same($span->serviceName(), $serviceName);
        Assert::same($span->scopeName, $serviceName);
        Assert::same($span->kind, ReceivedSpan::KIND_SERVER);
        Assert::same($span->attributes['order.id'] ?? null, 42);
        Assert::same($span->attributes['user.role'] ?? null, 'admin');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_OK);
        Assert::true($span->isRoot());
        Assert::int($span->endTimeUnixNano)->greaterThanOrEqual($span->startTimeUnixNano);
    }

    #[Env('OTEL_SERVICE_NAME', 'otel-bridge-acceptance-billing')]
    public function serviceNameComesFromEnv(): void
    {
        $this->tracer()->trace('invoice', static fn() => null);

        Assert::same($this->traces()->await(1)->named('invoice')->serviceName(), 'otel-bridge-acceptance-billing');
    }

    public function errorStatusArrivesWithDescription(): void
    {
        $this->tracer()->trace('pay', static fn(SpanInterface $span) => $span->setStatus('error', 'Payment declined'));

        $span = $this->traces()->await(1)->named('pay');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, 'Payment declined');
    }

    public function homogeneousListAttributeArrivesAsArray(): void
    {
        $this->tracer()->trace('tags', static fn(SpanInterface $span) => $span->setAttribute('tags', ['a', 'b']));

        Assert::same($this->traces()->await(1)->named('tags')->attributes['tags'] ?? null, ['a', 'b']);
    }

    public function scopedTracesArriveAsTree(): void
    {
        $tracer = $this->tracer();

        $tracer->trace('root', static fn() => $tracer->trace(
            'child',
            static fn() => $tracer->trace('grandchild', static fn() => null, scoped: true),
            scoped: true,
        ), scoped: true);

        $spans = $this->traces()->await(3);
        $root = $spans->named('root');
        $child = $spans->named('child');
        $grandchild = $spans->named('grandchild');
        Assert::count($spans, 3, "Received:
{$spans}");
        Assert::true($root->isRoot());
        Assert::same($child->parentSpanId, $root->spanId);
        Assert::same($grandchild->parentSpanId, $child->spanId);
        Assert::same($child->traceId, $root->traceId);
        Assert::same($grandchild->traceId, $root->traceId);
    }

    public function incomingTraceparentContinuesTheTrace(): void
    {
        $this->tracerWithContext(['traceparent' => self::TRACEPARENT])->trace('consume', static fn() => null);

        $span = $this->traces()->await(1)->named('consume');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
    }

    public function exceptionIsRecordedAsEvent(): void
    {
        $exception = self::rescue(fn() => $this->tracer()->trace(
            'explode',
            static fn() => throw new \RuntimeException('Boom'),
        ));

        $span = $this->traces()->await(1)->named('explode');
        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::count($span->events, 1);
        Assert::same($span->events[0]['name'], 'exception');
        Assert::same($span->events[0]['attributes']['exception.type'] ?? null, \RuntimeException::class);
        Assert::same($span->events[0]['attributes']['exception.message'] ?? null, 'Boom');
    }

    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function scopedTracesUnderIncomingTraceparentArriveAsTree(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('outer', static fn() => $tracer->trace('inner', static fn() => null, scoped: true), scoped: true);

        $spans = $this->traces()->await(2);
        $outer = $spans->named('outer');
        $inner = $spans->named('inner');
        Assert::same($outer->traceId, self::TRACE_ID);
        Assert::same($outer->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($inner->traceId, self::TRACE_ID);
        Assert::same($inner->parentSpanId, $outer->spanId);
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset and loses its name and attributes, see https://github.com/spiral/otel-bridge/issues/18')]
    public function throwingCallbackMarksSpanAsErrorAndKeepsNameAndAttributes(): void
    {
        self::rescue(fn() => $this->tracer()->trace('boom', static function (SpanInterface $span): void {
            $span->setAttribute('k', 'v');
            $span->updateName('renamed');
            throw new \RuntimeException('fail');
        }));

        $span = $this->traces()->await(1)->spans[0];
        Assert::same($span->name, 'renamed');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, 'fail');
        Assert::same($span->attributes['k'] ?? null, 'v');
        Assert::same($span->events[0]['name'] ?? null, 'exception');
    }

    #[DataSet([500, ReceivedSpan::STATUS_ERROR], 'int 500')]
    #[DataSet(['failed', ReceivedSpan::STATUS_UNSET], 'unknown string')]
    #[Skip('Known issue: int and unknown status codes are exported as OK, see https://github.com/spiral/otel-bridge/issues/19')]
    public function statusCodeFollowsOpenTelemetrySemantics(int|string $code, int $expected): void
    {
        $this->tracer()->trace('status', static fn(SpanInterface $span) => $span->setStatus($code));

        Assert::same($this->traces()->await(1)->named('status')->statusCode, $expected);
    }

    #[DataSet([['X'], ['X']], 'single-element list')]
    #[DataSet([['foo', 'bar', 42], ['foo', 'bar', '42']], 'mixed list')]
    #[DataSet([['a', null], ['a', '']], 'list with null')]
    #[Skip('Known issue: array attributes are dropped or collapsed, see https://github.com/spiral/otel-bridge/issues/20')]
    public function arrayAttributeArrivesAsHomogeneousList(array $value, array $expected): void
    {
        $this->tracer()->trace('attrs', static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        Assert::same($this->traces()->await(1)->named('attrs')->attributes['value'] ?? null, $expected);
    }

    /**
     * Runs a call expected to throw and hands back what it threw, so the test can go on to the spans.
     */
    private static function rescue(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
