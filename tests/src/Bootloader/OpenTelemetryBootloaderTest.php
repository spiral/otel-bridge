<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Bootloader;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Common\Dsn\ParserInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\OpenTelemetry\Tests\TestCase;
use OpenTelemetry\SDK\Trace\Tracer;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use OpenTelemetry\SDK\Common\Dsn\Parser;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Config\TelemetryConfig;
use Spiral\Telemetry\NullTracerFactory;
use Spiral\Telemetry\LogTracerFactory;

class OpenTelemetryBootloaderTest extends TestCase
{
    public function testTracerInterfaceBinding(): void
    {
        $this->assertContainerBound(TracerInterface::class, Tracer::class);
    }

    public function testTracerProviderInterfaceBinding(): void
    {
        $this->assertContainerBound(TracerProviderInterface::class, TracerProvider::class);
    }

    public function testSpanExporterInterfaceBinding(): void
    {
        $this->assertContainerBound(SpanExporterInterface::class, DeferredSpanExporter::class);
    }

    public function testParserInterfaceBinding(): void
    {
        $this->assertContainerBound(ParserInterface::class, Parser::class);
    }

    public function testTextMapPropagatorInterfaceBinding(): void
    {
        $this->assertContainerBound(TextMapPropagatorInterface::class, TraceContextPropagator::class);
    }

    public function testAssertOtelTracerRegistered(): void
    {
        $this->assertConfigHasFragments(TelemetryConfig::CONFIG, [
            'drivers' => [
                'null' => NullTracerFactory::class,
                'log' => LogTracerFactory::class,
                'otel' => TracerFactory::class
            ]
        ]);
    }
}
