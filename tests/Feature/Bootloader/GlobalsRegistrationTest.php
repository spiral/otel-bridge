<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\Config\ConfiguratorInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Propagation\LateBindingPropagator;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\RecordingSpanExporter;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Trace\ContextFinalizer;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Config\TelemetryConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The app registers its provider and propagator as the global ones that auto-instrumentation uses, so
 * spans of hooks, simulated here by a tracer taken from `Globals`, go through the app's pipeline.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(ContextFinalizer::class)]
#[Covers(LateBindingPropagator::class)]
final class GlobalsRegistrationTest extends TestCase
{
    /**
     * Taken from the container on first use, so a binding made after this bootloader is the global one too.
     */
    public function globalPropagatorIsTheOneTheAppBinds(): void
    {
        $this->getContainer()->bindSingleton(TextMapPropagatorInterface::class, TraceContextPropagator::getInstance());

        Assert::same(Globals::propagator()->fields(), TraceContextPropagator::getInstance()->fields());
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

    /**
     * Termination is not the end of a request: a span still active then is not reported.
     */
    public function spanActiveAtTerminationIsNotReported(): void
    {
        $this->useExporter();
        $span = $this->getContainer()->get(TracerInterface::class)->spanBuilder('active')->startSpan();
        $scope = $span->activate();

        try {
            $log = $this->logOf(fn() => $this->finalizer()->finalize(true));
        } finally {
            $scope->detach();
            $span->end();
        }

        Assert::same($log, []);
        Assert::instanceOf(Globals::propagator(), NoopTextMapPropagator::class);
    }

    /**
     * Without the SDK autoloader the global propagator and provider are the no-op ones again.
     */
    public function terminationTakesTheRegistrationBack(): void
    {
        $this->finalizer()->finalize(true);

        Assert::instanceOf(Globals::propagator(), NoopTextMapPropagator::class);
        Assert::instanceOf(Globals::tracerProvider(), NoopTracerProvider::class);
    }

    /**
     * The context `Globals` reads is built over the root: a span active when the app boots is not
     * kept active by it.
     */
    public function registrationDoesNotKeepWhatIsActiveAtBoot(): void
    {
        $this->finalizer()->finalize(true);
        $scope = Span::wrap(SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331'))->activate();
        try {
            $app = $this->makeApp();
            $active = Span::getCurrent()->getContext()->isValid();
            $app->getContainer()->get(FinalizerInterface::class)->finalize(true);
        } finally {
            $scope->detach();
        }

        Assert::false($active);
    }

    /**
     * The whole life of an app booted here, as the boot of the test app runs before the test.
     */
    public function bootRegistersTheDriverAndGlobalsUntilTermination(): void
    {
        $this->finalizer()->finalize(true);

        $container = $this->makeApp()->getContainer();
        $driver = $container->get(ConfiguratorInterface::class)->getConfig(TelemetryConfig::CONFIG)['drivers']['otel'] ?? null;
        $propagator = Globals::propagator();
        $container->get(FinalizerInterface::class)->finalize(true);

        Assert::same($driver, TracerFactory::class);
        Assert::instanceOf($propagator, LateBindingPropagator::class);
        Assert::instanceOf(Globals::propagator(), NoopTextMapPropagator::class);
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
