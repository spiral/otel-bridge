<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Config\TelemetryConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(OpenTelemetryBootloader::class)]
final class OpenTelemetryBootloaderTest extends TestCase
{
    #[DataSet([TracerInterface::class, Tracer::class], 'tracer')]
    #[DataSet([TracerProviderInterface::class, TracerProvider::class], 'tracer provider')]
    #[DataSet([SpanExporterInterface::class, DeferredSpanExporter::class], 'span exporter')]
    #[DataSet([TextMapPropagatorInterface::class, TraceContextPropagator::class], 'propagator')]
    public function bindsOpenTelemetryService(string $alias, string $class): void
    {
        $this->assertContainerBound($alias, $class);
    }

    public function registersOtelTracerDriver(): void
    {
        $driver = $this->getConfig(TelemetryConfig::CONFIG)['drivers']['otel'] ?? null;

        Assert::same($driver, TracerFactory::class);
    }
}
