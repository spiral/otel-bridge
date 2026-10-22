<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\Boot\EnvironmentInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Test;

/**
 * Traces made through Spiral's TracerInterface, exported over OTLP/HTTP and read back from Trap.
 */
#[Test]
final class TracerTest extends TestCase
{
    public function traceArrivesWithServiceNameScopeKindAttributesAndStatus(): void
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
        Assert::same($span->scopeName, 'spiral/otel-bridge');
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
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::true($root->isRoot());
        Assert::same($child->parentSpanId, $root->spanId);
        Assert::same($grandchild->parentSpanId, $child->spanId);
        Assert::same($child->traceId, $root->traceId);
        Assert::same($grandchild->traceId, $root->traceId);
    }
}
