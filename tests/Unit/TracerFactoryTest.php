<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit;

use Testo\Test;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use Testo\Assert;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\ScopeInterface;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\TracerInterface as TelemetryTracerInterface;

#[Test]
final class TracerFactoryTest
{
    public function testMake(): void
    {
        $factory = new TracerFactory(
            Double::for(ScopeInterface::class)->strict(),
            Double::for(TracerInterface::class)->strict(),
            $propagator = Double::for(TextMapPropagatorInterface::class)->strict()
        );
        $propagator->allows('fields')->with(Argument::none())->returns([]);
        Assert::instanceOf($factory->make(['foo' => 'bar']), TelemetryTracerInterface::class);
    }

    public function testMakePropagatesContext(): void
    {
        $factory = new TracerFactory(
            Double::for(ScopeInterface::class)->strict(),
            Double::for(TracerInterface::class)->strict(),
            $propagator = Double::for(TextMapPropagatorInterface::class)->strict()
        );
        $propagator->allows('fields')->with(Argument::none())->returns(['foo3', 'foo4', 'foo5']);
        $tracer = $factory->make([
            'foo1' => 'bar1',
            'foo2' => 'bar2',
            'foo3' => 'bar3',
            'foo4' => 'bar4',
            'foo5' => 'bar5',
            'foo6' => 'bar6',
        ]);
        $propagated = ['foo3' => 'bar3', 'foo4' => 'bar4', 'foo5' => 'bar5'];
        Assert::equals($tracer->getContext(), $propagated);
    }

    public function testMakeMatchesFieldsCaseInsensitively(): void
    {
        $factory = new TracerFactory(
            Double::for(ScopeInterface::class)->strict(),
            Double::for(TracerInterface::class)->strict(),
            $propagator = Double::for(TextMapPropagatorInterface::class)->strict()
        );
        $propagator->allows('fields')->with(Argument::none())->returns(['traceparent', 'TraceState', 'Baggage']);
        $tracer = $factory->make([
            'X-Request-Id' => 'id',
            'TRACEPARENT' => 'parent',
            'Accept' => 'json',
            'tracestate' => 'state',
            'baggage' => 'bag',
            'Zipkin' => 'zip',
        ]);
        Assert::same($tracer->getContext(), ['TRACEPARENT' => 'parent', 'tracestate' => 'state', 'baggage' => 'bag']);
    }
}
