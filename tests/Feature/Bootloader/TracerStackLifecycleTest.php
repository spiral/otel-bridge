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
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\RecordingSpanExporter;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The tracing stack is one per app, flushed by `finalize(false)` at the end of each request or job and
 * shut down by `finalize(true)` with the app. The processor is a batch one over a recording exporter.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
final class TracerStackLifecycleTest extends TestCase
{
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
     * `false` is not worth a report: failed exports are logged by the SDK.
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
            $afterRequest = $log->entries;
            # While the log is still captured: the app lives to the end of the process otherwise.
            $this->finalizer()->finalize(true);
        } finally {
            Logging::reset();
        }

        Assert::same($afterRequest, [
            'error: Unable to export spans; could not report the failure: Reporter failed [Exporter failed]',
        ]);
    }

    /**
     * A reporter that needs the tracer can be bound: it is resolved only once there is a failure to report.
     */
    public function reporterMayDependOnTracer(): void
    {
        $failure = new \RuntimeException('Exporter failed');
        $this->useExporter(new RecordingSpanExporter($failure));
        $reporter = $this->reporter();
        $this->getContainer()->bindSingleton(
            ExceptionReporterInterface::class,
            static fn(TracerInterface $tracer): ExceptionReporterInterface => $reporter,
        );

        $this->startSpan('request');
        $this->finalizer()->finalize(false);

        Assert::same($reporter->reported, [$failure]);
    }

    /**
     * The provider is first built inside a request scope, which is gone by the time the failure is reported.
     */
    public function failureIsReportedAfterScopeOfFirstUseEnds(): void
    {
        $failure = new \RuntimeException('Exporter failed');
        $this->useExporter(new RecordingSpanExporter($failure));
        $reporter = $this->useReporter();

        $this->getContainer()->runScope(new Scope('http'), static fn(Container $http): mixed => $http->runScope(
            new Scope('http.request'),
            static fn(TracerInterface $tracer) => $tracer->spanBuilder('request')->startSpan()->end(),
        ));
        $this->finalizer()->finalize(false);

        Assert::same($reporter->reported, [$failure]);
    }

    private function useExporter(RecordingSpanExporter $exporter): RecordingSpanExporter
    {
        $container = $this->getContainer();
        $container->bindSingleton(SpanExporterInterface::class, $exporter);
        $container->bindSingleton(SpanProcessorInterface::class, BatchSpanProcessor::builder($exporter)->build());

        return $exporter;
    }

    private function useReporter(?\Throwable $failure = null): object
    {
        $reporter = $this->reporter($failure);
        $this->getContainer()->bindSingleton(ExceptionReporterInterface::class, $reporter);

        return $reporter;
    }

    /**
     * A reporter that keeps what it gets, or throws `$failure` when given one.
     */
    private function reporter(?\Throwable $failure = null): object
    {
        return new class($failure) implements ExceptionReporterInterface {
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
