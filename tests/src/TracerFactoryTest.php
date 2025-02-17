<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\ScopeInterface;
use Spiral\OpenTelemetry\TracerFactory;
use Mockery as m;
use Spiral\Telemetry\TracerInterface as TelemetryTracerInterface;

class TracerFactoryTest extends TestCase
{
    public function testMake(): void
    {
        $factory = new TracerFactory(
            m::mock(ScopeInterface::class),
            m::mock(TracerInterface::class),
            $propagator = m::mock(TextMapPropagatorInterface::class)
        );

        $propagator->shouldReceive('fields')->withNoArgs()->andReturn();

        $this->assertInstanceOf(TelemetryTracerInterface::class, $factory->make(['foo' => 'bar']));
    }

    public function testMakePropagatesContext(): void
    {
        $factory = new TracerFactory(
            m::mock(ScopeInterface::class),
            m::mock(TracerInterface::class),
            $propagator = m::mock(TextMapPropagatorInterface::class)
        );

        $propagator->shouldReceive('fields')->withNoArgs()->andReturn(['foo3', 'foo4', 'foo5']);

        $tracer = $factory->make([
            'foo1' => 'bar1',
            'foo2' => 'bar2',
            'foo3' => 'bar3',
            'foo4' => 'bar4',
            'foo5' => 'bar5',
            'foo6' => 'bar6',
        ]);

        $propagated = ['foo3' => 'bar3', 'foo4' => 'bar4', 'foo5' => 'bar5'];

        $this->assertEquals($propagated, $tracer->getContext());
    }
}
