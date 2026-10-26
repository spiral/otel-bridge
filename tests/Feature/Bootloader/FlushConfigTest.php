<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\FakeClock;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\RecordingSpanExporter;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Spiral\Telemetry\ClockInterface;
use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `opentelemetry.flush`: which ends of requests or jobs, `finalize(false)`, flush the provider. Each request
 * here ends one span, so what a flush exports tells which requests it came after.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
final class FlushConfigTest extends TestCase
{
    private RecordingSpanExporter $exporter;
    private FakeClock $clock;

    public function everyRequestIsFlushedByDefault(): void
    {
        $this->useExporter();

        $flushes = $this->requests(3);

        Assert::same($flushes, [['1'], ['2'], ['3']]);
    }

    #[Config('opentelemetry.flush.every', 3)]
    public function everyNthRequestIsFlushed(): void
    {
        $this->useExporter();

        $flushes = $this->requests(7);
        $this->finalizer()->finalize(true);

        Assert::same($flushes, [[], [], ['1', '2', '3'], [], [], ['4', '5', '6'], []]);
        Assert::same($this->exporter->exported, ['1', '2', '3', '4', '5', '6', '7']);
        Assert::same($this->exporter->calls, ['forceFlush', 'forceFlush', 'shutdown']);
    }

    #[Config('opentelemetry.flush.every', 0)]
    public function zeroFlushesOnlyOnShutdown(): void
    {
        $this->useExporter();

        $flushes = $this->requests(3);
        $this->finalizer()->finalize(true);

        Assert::same($flushes, [[], [], []]);
        Assert::same($this->exporter->exported, ['1', '2', '3']);
        Assert::same($this->exporter->calls, ['shutdown']);
    }

    /**
     * The time counts from the provider being built, then from the last flush.
     */
    #[Config('opentelemetry.flush.every', 0)]
    #[Config('opentelemetry.flush.interval', 10)]
    public function intervalFlushesOnceItHasPassed(): void
    {
        $this->useExporter();

        $flushes = $this->requests(4, seconds: [5, 5, 9.5, 0.5]);

        Assert::same($flushes, [[], ['1', '2'], [], ['3', '4']]);
    }

    /**
     * Either condition flushes, and a flush starts both the count and the time anew.
     */
    #[Config('opentelemetry.flush.every', 3)]
    #[Config('opentelemetry.flush.interval', 10)]
    public function countOrIntervalFlushesWhicheverComesFirst(): void
    {
        $this->useExporter();

        $flushes = $this->requests(6, seconds: [1, 10, 1, 1, 1, 9]);

        Assert::same($flushes, [[], ['1', '2'], [], [], ['3', '4', '5'], []]);
    }

    /**
     * Ends a span in each of `$count` requests, the clock advanced by `$seconds[$i]` before the request ends.
     *
     * @param list<int|float> $seconds
     *
     * @return list<list<string>> The spans exported at the end of each request.
     */
    private function requests(int $count, array $seconds = []): array
    {
        $tracer = $this->getContainer()->get(TracerInterface::class);
        $flushes = [];
        for ($i = 1; $i <= $count; ++$i) {
            $tracer->spanBuilder((string) $i)->startSpan()->end();
            $this->clock->advance($seconds[$i - 1] ?? 0);
            $before = \count($this->exporter->exported);
            $this->finalizer()->finalize(false);
            $flushes[] = \array_slice($this->exporter->exported, $before);
        }

        return $flushes;
    }

    private function useExporter(): void
    {
        $this->exporter = new RecordingSpanExporter();
        $this->clock = new FakeClock();
        $container = $this->getContainer();
        $container->bindSingleton(ClockInterface::class, $this->clock);
        $container->bindSingleton(SpanExporterInterface::class, $this->exporter);
        $container->bindSingleton(SpanProcessorInterface::class, BatchSpanProcessor::builder($this->exporter)->build());
    }

    private function finalizer(): FinalizerInterface
    {
        return $this->getContainer()->get(FinalizerInterface::class);
    }
}
