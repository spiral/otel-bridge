<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Spiral\OpenTelemetry\Trace\UnsampledContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * Hooks of ext-opentelemetry trace the HTTP request of the exporter too. The exporter here starts a span
 * with the global tracer provider the way such a hook does, at each call, and records whether it is sampled.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
#[Covers(UnsampledContext::class)]
final class ExporterSelfTracingTest extends TestCase
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const SPAN_ID = 'b7ad6b7169203331';

    #[DataSet(['simple'], 'simple processor')]
    #[DataSet(['batch'], 'batch processor')]
    public function spansStartedWhileExportingAreNotSampled(string $processor): void
    {
        $exporter = $this->useExporter();
        # The processor is built in a request, under whatever span is active there.
        $scope = Span::wrap(SpanContext::create(self::TRACE_ID, self::SPAN_ID, TraceFlags::SAMPLED))->activate();
        try {
            self::withProcessEnv(
                ['OTEL_PHP_TRACES_PROCESSOR' => $processor],
                fn() => $this->getContainer()->get(SpanProcessorInterface::class),
            );
        } finally {
            $scope->detach();
        }

        $this->startSpan('request');
        $this->finalizer()->finalize(false);
        $this->finalizer()->finalize(true);

        Assert::same($exporter->exported, ['request']);
        Assert::same($exporter->sampled, ['export' => [false], 'forceFlush' => [false], 'shutdown' => [false]]);
    }

    public function spansOutsideExportAreSampled(): void
    {
        $this->useExporter();

        $span = Globals::tracerProvider()->getTracer('hook')->spanBuilder('hook')->startSpan();
        $span->end();

        Assert::true($span->getContext()->isSampled());
    }

    #[AfterTest]
    public function terminate(): void
    {
        $this->finalizer()->finalize(true);
    }

    private function useExporter(): object
    {
        $exporter = new class implements SpanExporterInterface {
            /** @var list<string> */
            public array $exported = [];

            /** @var array<string, list<bool>> */
            public array $sampled = [];

            #[\Override]
            public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
            {
                foreach ($batch as $span) {
                    $this->exported[] = $span->getName();
                }
                $this->hook('export');

                return new CompletedFuture(true);
            }

            #[\Override]
            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                $this->hook('shutdown');

                return true;
            }

            #[\Override]
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                $this->hook('forceFlush');

                return true;
            }

            private function hook(string $call): void
            {
                $span = Globals::tracerProvider()->getTracer('hook')->spanBuilder($call)->startSpan();
                $span->end();
                $this->sampled[$call][] = $span->getContext()->isSampled();
            }
        };
        $this->getContainer()->bindSingleton(SpanExporterInterface::class, $exporter);

        return $exporter;
    }

    private function startSpan(string $name): void
    {
        $this->getContainer()->get(TracerProviderInterface::class)->getTracer('test')->spanBuilder($name)->startSpan()->end();
    }

    private function finalizer(): FinalizerInterface
    {
        return $this->getContainer()->get(FinalizerInterface::class);
    }

    /**
     * Runs `$call` with process env values set, where the SDK reads them, and puts the old values back.
     *
     * @template T
     * @param array<string, string> $env
     * @param \Closure(): T $call
     * @return T
     */
    private static function withProcessEnv(array $env, \Closure $call): mixed
    {
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = \getenv($key);
            \putenv("{$key}={$value}");
        }

        try {
            return $call();
        } finally {
            foreach ($previous as $key => $value) {
                \putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    }
}
