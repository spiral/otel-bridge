<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Bootloader;

use Testo\Test;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\OpenTelemetry\Tests\TestCase;
use OpenTelemetry\SDK\Trace\Tracer;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;

final class OpenTelemetryBootloaderTest extends TestCase
{
    #[Test]
    public function testTracerInterfaceBinding(): void
    {
        $this->assertContainerBound(TracerInterface::class, Tracer::class);
    }

    #[Test]
    public function testTracerProviderInterfaceBinding(): void
    {
        $this->assertContainerBound(TracerProviderInterface::class, TracerProvider::class);
    }

    #[Test]
    public function testSpanExporterInterfaceBinding(): void
    {
        $this->assertContainerBound(SpanExporterInterface::class, DeferredSpanExporter::class);
    }

    #[Test]
    public function testTextMapPropagatorInterfaceBinding(): void
    {
        $this->assertContainerBound(TextMapPropagatorInterface::class, TraceContextPropagator::class);
    }
}
