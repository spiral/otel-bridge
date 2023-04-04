<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Trace;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Spiral\OpenTelemetry\Tests\TestCase;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Mockery as m;

class DeferredSpanExporterTest extends TestCase
{
    public function testExport(): void
    {
        $exporter = new DeferredSpanExporter($factory = m::mock(ExporterFactory::class));

        $factory->shouldReceive('create')->withNoArgs()->andReturn(
            $span = m::mock(SpanExporterInterface::class)
        );

        $span->shouldReceive('export')->with(['foo', 'bar'], null)->andReturn(
            m::mock(FutureInterface::class)
        );

        $this->assertInstanceOf(FutureInterface::class, $exporter->export(['foo', 'bar']));
    }

    public function testExportWithCancellation(): void
    {
        $exporter = new DeferredSpanExporter($factory = m::mock(ExporterFactory::class));

        $cancellation = m::mock(CancellationInterface::class);

        $factory->shouldReceive('create')->withNoArgs()->andReturn(
            $span = m::mock(SpanExporterInterface::class)
        );

        $span->shouldReceive('export')->with(['foo', 'bar'], $cancellation)->andReturn(
            m::mock(FutureInterface::class)
        );

        $this->assertInstanceOf(FutureInterface::class, $exporter->export(['foo', 'bar'], $cancellation));
    }

    public function testFromConnectionString(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Method is not supported.');

        $exporter = new DeferredSpanExporter(m::mock(ExporterFactory::class));

        $exporter::fromConnectionString('https://test.com', 'foo', 'bar');
    }

    /**
     * @dataProvider provideData
     */
    public function testOtherMethods(string $method, $cancellation): void
    {
        $exporter = new DeferredSpanExporter($factory = m::mock(ExporterFactory::class));

        $factory->shouldReceive('create')->withNoArgs()->andReturn(
            $span = m::mock(SpanExporterInterface::class)
        );

        $span->shouldReceive($method)->with($cancellation)->andReturn(true);

        $this->assertSame(true, $cancellation ? $exporter->$method($cancellation) : $exporter->$method());
    }

    public function provideData(): array
    {
        return [
            [
                'method' => 'shutdown',
                'cancellation' => null
            ],
            [
                'method' => 'shutdown',
                'cancellation' => m::mock(CancellationInterface::class)
            ],
            [
                'method' => 'forceFlush',
                'cancellation' => null
            ],
            [
                'method' => 'forceFlush',
                'cancellation' => m::mock(CancellationInterface::class)
            ],
        ];
    }
}
