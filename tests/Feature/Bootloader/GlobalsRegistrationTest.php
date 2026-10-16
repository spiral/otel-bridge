<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\RecordingSpanExporter;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Trace\ContextFinalizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * The app registers its provider and propagator as the global ones that auto-instrumentation uses, so
 * spans of hooks, simulated here by a tracer taken from `Globals`, go through the app's pipeline.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(ContextFinalizer::class)]
final class GlobalsRegistrationTest extends TestCase
{
    public function globalPropagatorIsTheAppOne(): void
    {
        Assert::same(Globals::propagator(), $this->getContainer()->get(TextMapPropagatorInterface::class));
    }

    public function globalSpansAreFlushedWithAppSpansAtRequestEnd(): void
    {
        $exporter = $this->useExporter();

        Globals::tracerProvider()->getTracer('hook')->spanBuilder('hook')->startSpan()->end();
        $this->getContainer()->get(TracerInterface::class)->spanBuilder('app')->startSpan()->end();
        $queued = $exporter->exported;
        $this->finalizer()->finalize(false);

        Assert::same($queued, []);
        Assert::same($exporter->exported, ['hook', 'app']);
        Assert::same($exporter->calls, ['forceFlush']);
    }

    public function terminationTakesTheRegistrationBack(): void
    {
        $propagator = $this->getContainer()->get(TextMapPropagatorInterface::class);

        $this->finalizer()->finalize(true);

        Assert::notSame(Globals::propagator(), $propagator);
    }

    public function spanLeftActiveAfterRequestIsReported(): void
    {
        $this->useExporter();
        $span = $this->getContainer()->get(TracerInterface::class)->spanBuilder('leaked')->startSpan();
        $scope = $span->activate();

        try {
            $leaked = $this->logOf(fn() => $this->finalizer()->finalize(false));
        } finally {
            $scope->detach();
            $span->end();
        }
        $clean = $this->logOf(fn() => $this->finalizer()->finalize(false));

        $id = $span->getContext();
        Assert::same($leaked, [
            "warning: A span is still active after the request or job has ended: trace {$id->getTraceId()}, span {$id->getSpanId()}. Every later span of the process will be its child.",
        ]);
        Assert::same($clean, []);
    }

    #[AfterTest]
    public function terminate(): void
    {
        $this->finalizer()->finalize(true);
    }

    /**
     * @return list<string> What the SDK log got while `$call` ran.
     */
    private function logOf(\Closure $call): array
    {
        $log = new class implements LogWriterInterface {
            /** @var list<string> */
            public array $entries = [];

            #[\Override]
            public function write($level, string $message, array $context): void
            {
                $this->entries[] = "{$level}: {$message}";
            }
        };
        Logging::setLogWriter($log);

        try {
            $call();
        } finally {
            Logging::reset();
        }

        return $log->entries;
    }

    private function useExporter(): RecordingSpanExporter
    {
        $exporter = new RecordingSpanExporter();
        $container = $this->getContainer();
        $container->bindSingleton(SpanExporterInterface::class, $exporter);
        $container->bindSingleton(SpanProcessorInterface::class, BatchSpanProcessor::builder($exporter)->build());

        return $exporter;
    }

    private function finalizer(): FinalizerInterface
    {
        return $this->getContainer()->get(FinalizerInterface::class);
    }
}
