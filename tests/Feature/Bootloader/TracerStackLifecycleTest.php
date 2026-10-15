<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\Core\Container;
use Spiral\Core\Scope;
use Spiral\Exceptions\ExceptionReporterInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\RecordingSpanExporter;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * The tracing stack is one per app, flushed by `finalize(false)` at the end of each request or job and
 * shut down by `finalize(true)` with the app. The processor is a batch one over a recording exporter.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
final class TracerStackLifecycleTest extends TestCase
{
    private ?RecordingSpanExporter $exporter = null;

    /**
     * Resolved first in a request scope, the way an HTTP app meets the tracer, and then in the root.
     */
    #[DataSet([TracerProviderInterface::class], 'tracer provider')]
    #[DataSet([TracerInterface::class], 'OTel tracer')]
    #[DataSet([SpanExporterInterface::class], 'span exporter')]
    #[DataSet([SpanProcessorInterface::class], 'span processor')]
    public function tracingServiceIsOneAcrossScopes(string $service): void
    {
        $container = $this->getContainer();

        $scoped = $container->runScope(new Scope('http'), static fn(Container $http): object => $http->runScope(
            new Scope('http.request'),
            static fn(Container $request): object => $request->get($service),
        ));
        $root = $container->get($service);

        Assert::same($scoped, $root);
    }

    public function requestEndFlushesQueuedSpans(): void
    {
        $exporter = $this->useExporter(new RecordingSpanExporter());

        $this->startSpan('request');
        $queued = $exporter->exported;
        $this->finalizer()->finalize(false);

        Assert::same($queued, []);
        Assert::same($exporter->exported, ['request']);
        Assert::same($exporter->calls, ['forceFlush']);
    }

    public function tracingGoesOnAfterEachRequestEnd(): void
    {
        $exporter = $this->useExporter(new RecordingSpanExporter());

        $this->startSpan('first');
        $this->finalizer()->finalize(false);
        $this->startSpan('second');
        $this->finalizer()->finalize(false);

        Assert::same($exporter->exported, ['first', 'second']);
    }

    public function terminationShutsDownWithQueuedSpans(): void
    {
        $exporter = $this->useExporter(new RecordingSpanExporter());

        $this->startSpan('last');
        $this->finalizer()->finalize(true);
        $this->finalizer()->finalize(true);

        Assert::same($exporter->exported, ['last']);
        Assert::same($exporter->calls, ['shutdown']);
    }

    #[DataSet([false], 'request end')]
    #[DataSet([true], 'termination')]
    public function throwingExporterIsReportedAndNotRethrown(bool $terminate): void
    {
        $failure = new \RuntimeException('Exporter failed');
        $this->useExporter(new RecordingSpanExporter($failure));
        $reporter = $this->useReporter();

        $this->startSpan('request');
        $this->finalizer()->finalize($terminate);

        Assert::same($reporter->reported, [$failure]);
    }

    public function tracingGoesOnAfterThrowingRequestEnd(): void
    {
        $exporter = $this->useExporter(new RecordingSpanExporter(new \RuntimeException('Exporter failed')));
        $this->useReporter();

        $this->startSpan('first');
        $this->finalizer()->finalize(false);
        $exporter->result = true;
        $this->startSpan('second');
        $this->finalizer()->finalize(false);

        Assert::same($exporter->exported, ['first', 'second']);
    }

    /**
     * A `false` result is a failed export, which the SDK has logged already.
     */
    #[DataSet([false], 'request end')]
    #[DataSet([true], 'termination')]
    public function failedFlushIsNotReported(bool $terminate): void
    {
        $exporter = $this->useExporter(new RecordingSpanExporter(false));
        $reporter = $this->useReporter();

        $this->startSpan('request');
        $this->finalizer()->finalize($terminate);

        Assert::count($exporter->calls, 1);
        Assert::same($reporter->reported, []);
    }

    public function failingReporterIsLoggedAndNotRethrown(): void
    {
        $this->useExporter(new RecordingSpanExporter(new \RuntimeException('Exporter failed')));
        $this->useReporter(new \LogicException('Reporter failed'));
        $log = new class implements LogWriterInterface {
            /** @var list<string> */
            public array $entries = [];

            #[\Override]
            public function write($level, string $message, array $context): void
            {
                $exception = $context['exception'] ?? null;
                $this->entries[] = "{$level}: {$message}" . ($exception instanceof \Throwable ? " [{$exception->getMessage()}]" : '');
            }
        };
        Logging::setLogWriter($log);

        try {
            $this->startSpan('request');
            $this->finalizer()->finalize(false);
        } finally {
            Logging::reset();
        }

        Assert::same($log->entries, ['error: Unable to report a span flush failure [Reporter failed]']);
    }

    /**
     * spiral/testing keeps the kernel to the end of the process, whose destructor runs finalize(true)
     * once more, with nobody capturing what fails there.
     */
    #[AfterTest]
    public function calmExporter(): void
    {
        $this->exporter === null or $this->exporter->result = true;
    }

    private function useExporter(RecordingSpanExporter $exporter): RecordingSpanExporter
    {
        $this->exporter = $exporter;
        $container = $this->getContainer();
        $container->bindSingleton(SpanExporterInterface::class, $exporter);
        $container->bindSingleton(SpanProcessorInterface::class, BatchSpanProcessor::builder($exporter)->build());

        return $exporter;
    }

    /**
     * Binds a reporter that keeps what it gets, or throws `$failure` when given one.
     */
    private function useReporter(?\Throwable $failure = null): object
    {
        $reporter = new class($failure) implements ExceptionReporterInterface {
            /** @var list<\Throwable> */
            public array $reported = [];

            public function __construct(
                private readonly ?\Throwable $failure,
            ) {}

            #[\Override]
            public function report(\Throwable $exception): void
            {
                $this->failure === null or throw $this->failure;
                $this->reported[] = $exception;
            }
        };
        $this->getContainer()->bindSingleton(ExceptionReporterInterface::class, $reporter);

        return $reporter;
    }

    private function startSpan(string $name): void
    {
        $this->getContainer()->get(TracerInterface::class)->spanBuilder($name)->startSpan()->end();
    }

    private function finalizer(): FinalizerInterface
    {
        return $this->getContainer()->get(FinalizerInterface::class);
    }
}
