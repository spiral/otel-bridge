<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit;

use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Matching\Argument;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\ScopeInterface;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\TracerInterface as TelemetryTracerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TracerFactory::class)]
final class TracerFactoryTest
{
    private DoubleInterface&TextMapPropagatorInterface $propagator;
    private TracerFactory $factory;

    #[BeforeTest]
    public function createFactory(): void
    {
        $this->propagator = Double::for(TextMapPropagatorInterface::class)->strict();
        $this->factory = new TracerFactory(
            Double::for(ScopeInterface::class)->strict(),
            Double::for(TracerInterface::class)->strict(),
            $this->propagator,
        );
    }

    public function makesTelemetryTracer(): void
    {
        $this->propagator->allows('fields')->with(Argument::none())->returns([]);

        $tracer = $this->factory->make(['foo' => 'bar']);

        Assert::instanceOf($tracer, TelemetryTracerInterface::class);
    }

    #[DataSet([
        ['foo3', 'foo4', 'foo5'],
        ['foo1' => 'bar1', 'foo2' => 'bar2', 'foo3' => 'bar3', 'foo4' => 'bar4', 'foo5' => 'bar5', 'foo6' => 'bar6'],
        ['foo3' => 'bar3', 'foo4' => 'bar4', 'foo5' => 'bar5'],
    ], 'same case')]
    #[DataSet([
        ['traceparent', 'TraceState', 'Baggage'],
        ['X-Request-Id' => 'id', 'TRACEPARENT' => 'parent', 'Accept' => 'json', 'tracestate' => 'state', 'baggage' => 'bag', 'Zipkin' => 'zip'],
        ['TRACEPARENT' => 'parent', 'tracestate' => 'state', 'baggage' => 'bag'],
    ], 'case-insensitive')]
    public function contextKeepsOnlyPropagatorFields(array $fields, array $headers, array $expected): void
    {
        $this->propagator->allows('fields')->with(Argument::none())->returns($fields);

        $tracer = $this->factory->make($headers);

        Assert::same($tracer->getContext(), $expected);
    }
}
