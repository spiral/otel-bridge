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
}
