<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Trace;

use Testo\Data\DataProvider;
use Testo\Test;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use Testo\Assert;
use Testo\Expect;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;

#[Test]
final class DeferredSpanExporterTest
{
    public function testExport(): void
    {
        $exporter = new DeferredSpanExporter($factory = Double::for(ExporterFactory::class)->strict());
        $factory->allows('create')->with(Argument::none())->returns($span = Double::for(SpanExporterInterface::class)->strict());
        $span->allows('export')->with(['foo', 'bar'], null)->returns(Double::for(FutureInterface::class)->strict());
        Assert::instanceOf($exporter->export(['foo', 'bar']), FutureInterface::class);
    }

    public function testExportWithCancellation(): void
    {
        $exporter = new DeferredSpanExporter($factory = Double::for(ExporterFactory::class)->strict());
        $cancellation = Double::for(CancellationInterface::class)->strict();
        $factory->allows('create')->with(Argument::none())->returns($span = Double::for(SpanExporterInterface::class)->strict());
        $span->allows('export')->with(['foo', 'bar'], $cancellation)->returns(Double::for(FutureInterface::class)->strict());
        Assert::instanceOf($exporter->export(['foo', 'bar'], $cancellation), FutureInterface::class);
    }

    public function testFromConnectionString(): void
    {
        Expect::exception(\Exception::class)->withMessageContaining('Method is not supported.');
        $exporter = new DeferredSpanExporter(Double::for(ExporterFactory::class)->strict());
        $exporter::fromConnectionString('https://test.com', 'foo', 'bar');
    }

    #[DataProvider('provideData')]
    public function testOtherMethods(string $method, $cancellation): void
    {
        $exporter = new DeferredSpanExporter($factory = Double::for(ExporterFactory::class)->strict());

        $factory->allows('create')->with(Argument::none())->returns($span = Double::for(SpanExporterInterface::class)->strict());

        $span->allows($method)->with($cancellation)->returns(true);

        Assert::same($cancellation ? $exporter->$method($cancellation) : $exporter->$method(), true);
    }

    public static function provideData(): array
    {
        return [
            [
                'method' => 'shutdown',
                'cancellation' => null
            ],
            [
                'method' => 'shutdown',
                'cancellation' => Double::for(CancellationInterface::class)->strict()
            ],
            [
                'method' => 'forceFlush',
                'cancellation' => null
            ],
            [
                'method' => 'forceFlush',
                'cancellation' => Double::for(CancellationInterface::class)->strict()
            ],
        ];
    }
}
