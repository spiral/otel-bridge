<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\SdkLogWriter;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Skip;
use Testo\Test;

/**
 * How the app behaves with each `OTEL_TRACES_EXPORTER` setting: what reaches Trap, what the SDK reports
 * through its internal logger, and whether flushing and shutting down are safe to call from app code.
 *
 * Expectations:
 * - `none` means "no exporter" and is a valid setting, so it is silent:
 *   https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#exporter-selection
 * - an unknown value is reported, never thrown at run time:
 *   https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#enum
 *   https://opentelemetry.io/docs/specs/otel/error-handling/#basic-error-handling-principles
 */
#[Test]
final class ExporterLifecycleTest extends TestCase
{
    private const SPANS = ['first', 'second', 'third'];

    private SdkLogWriter $log;

    /** Results of the flushes made by {@see flushOnFinalize()}; disarmed after the test. */
    private ?\stdClass $finalizerFlushes = null;

    #[BeforeTest]
    public function captureSdkLog(): void
    {
        $this->log = new SdkLogWriter();
        Logging::setLogWriter($this->log);
    }

    #[AfterTest]
    public function restoreSdkLog(): void
    {
        Logging::reset();
    }

    #[AfterTest]
    public function disarmFinalizerFlush(): void
    {
        # The kernel runs finalizers again when it is destructed, after the env of the test is gone.
        $this->finalizerFlushes === null or $this->finalizerFlushes->armed = false;
    }

    public function otlpExportsEverySpanWithoutReportingProblems(): void
    {
        $this->traceSpans();
        $flushed = $this->provider()->forceFlush();

        $spans = $this->traces()->await(3);
        Assert::true($flushed);
        Assert::count($spans, 3, "Received:\n{$spans}");
        foreach (self::SPANS as $name) {
            $spans->named($name);
        }
        $this->assertNoProblems();
    }

    #[Env('OTEL_PHP_TRACES_PROCESSOR', 'batch')]
    public function otlpBatchExportsOnForceFlushWithoutReportingProblems(): void
    {
        $this->traceSpans();
        $flushed = $this->provider()->forceFlush();

        $spans = $this->traces()->await(3);
        Assert::true($flushed);
        Assert::count($spans, 3, "Received:\n{$spans}");
        $this->assertNoProblems();
    }

    /**
     * Another registered exporter than OTLP: spans go there, not to Trap, and nothing fails.
     */
    #[Env('OTEL_TRACES_EXPORTER', 'memory')]
    public function nonOtlpExporterIsUsedInsteadOfTrap(): void
    {
        $this->traceSpans();
        $flushed = $this->provider()->forceFlush();
        $shutDown = $this->processor()->shutdown();

        Assert::true($flushed);
        Assert::true($shutDown);
        $this->assertNothingArrives();
        $this->assertNoProblems();
    }

    #[Env('OTEL_TRACES_EXPORTER', 'none')]
    public function noneSendsNothingToTrap(): void
    {
        $this->traceSpans();
        $this->provider()->forceFlush();

        $this->assertNothingArrives();
    }

    #[Env('OTEL_TRACES_EXPORTER', 'none')]
    public function noneReportsNothingWithSimpleProcessor(): void
    {
        $this->traceSpans();

        $this->assertNoProblems();
    }

    #[Env('OTEL_TRACES_EXPORTER', 'none')]
    #[Env('OTEL_PHP_TRACES_PROCESSOR', 'batch')]
    public function noneReportsNothingWithBatchProcessor(): void
    {
        $this->traceSpans();
        $this->provider()->forceFlush();
        $this->processor()->shutdown();

        $this->assertNoProblems();
        $this->assertNothingArrives();
    }

    /**
     * Nothing was to be exported, so nothing failed: ForceFlush and Shutdown report success.
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#forceflush
     */
    #[Env('OTEL_TRACES_EXPORTER', 'none')]
    #[DataSet([TracerProviderInterface::class, 'forceFlush'], 'provider forceFlush')]
    #[DataSet([TracerProviderInterface::class, 'shutdown'], 'provider shutdown')]
    #[DataSet([SpanProcessorInterface::class, 'forceFlush'], 'processor forceFlush')]
    #[DataSet([SpanProcessorInterface::class, 'shutdown'], 'processor shutdown')]
    public function noneFlushAndShutdownSucceedWithSimpleProcessor(string $service, string $method): void
    {
        $this->traceSpans();

        $result = $this->getContainer()->get($service)->{$method}();

        Assert::true($result);
    }

    #[Env('OTEL_TRACES_EXPORTER', 'none')]
    #[Env('OTEL_PHP_TRACES_PROCESSOR', 'batch')]
    #[DataSet([TracerProviderInterface::class, 'forceFlush'], 'provider forceFlush')]
    #[DataSet([TracerProviderInterface::class, 'shutdown'], 'provider shutdown')]
    #[DataSet([SpanProcessorInterface::class, 'forceFlush'], 'processor forceFlush')]
    #[DataSet([SpanProcessorInterface::class, 'shutdown'], 'processor shutdown')]
    public function noneFlushAndShutdownSucceedWithBatchProcessor(string $service, string $method): void
    {
        $this->traceSpans();

        $result = $this->getContainer()->get($service)->{$method}();

        Assert::true($result);
    }

    /**
     * The per-request flush of #10, wired as a finalizer, on a worker with tracing turned off.
     */
    #[Env('OTEL_TRACES_EXPORTER', 'none')]
    public function noneKeepsFinalizerFlushSilent(): void
    {
        $flushes = $this->flushOnFinalize();
        $finalizer = $this->getContainer()->get(FinalizerInterface::class);

        $this->traceSpans();
        $finalizer->finalize();
        $this->traceSpans();
        $finalizer->finalize(terminate: true);

        Assert::same($flushes->results, [true, true]);
        $this->assertNoProblems();
    }

    /**
     * Same path as an exporter whose package is not installed: `Registry` knows no factory for the name.
     */
    #[Env('OTEL_TRACES_EXPORTER', 'bogus')]
    public function unknownExporterDoesNotBreakTracedCode(): void
    {
        $this->traceSpans();

        $this->assertNothingArrives();
    }

    #[Env('OTEL_TRACES_EXPORTER', 'bogus')]
    public function unknownExporterIsReported(): void
    {
        $this->traceSpans();

        $problems = $this->log->problems();
        Assert::true($problems !== [], 'An unknown exporter must be reported.');
        Assert::string($problems[0])->contains('bogus');
    }

    #[Env('OTEL_TRACES_EXPORTER', 'bogus')]
    #[DataSet([TracerProviderInterface::class, 'forceFlush', false], 'provider forceFlush')]
    #[DataSet([TracerProviderInterface::class, 'shutdown', null], 'provider shutdown')]
    #[DataSet([SpanProcessorInterface::class, 'forceFlush', false], 'processor forceFlush')]
    #[DataSet([SpanProcessorInterface::class, 'shutdown', null], 'processor shutdown')]
    public function unknownExporterNeverThrowsFromFlushOrShutdownWithSimpleProcessor(
        string $service,
        string $method,
        ?bool $expected,
    ): void {
        $this->traceSpans();

        $result = self::rescue(fn() => $this->getContainer()->get($service)->{$method}());

        self::assertFinishedWith($result, $expected);
    }

    #[Env('OTEL_TRACES_EXPORTER', 'bogus')]
    #[Env('OTEL_PHP_TRACES_PROCESSOR', 'batch')]
    #[DataSet([TracerProviderInterface::class, 'forceFlush', false], 'provider forceFlush')]
    #[DataSet([TracerProviderInterface::class, 'shutdown', null], 'provider shutdown')]
    #[DataSet([SpanProcessorInterface::class, 'forceFlush', false], 'processor forceFlush')]
    #[DataSet([SpanProcessorInterface::class, 'shutdown', null], 'processor shutdown')]
    public function unknownExporterNeverThrowsFromFlushOrShutdownWithBatchProcessor(
        string $service,
        string $method,
        ?bool $expected,
    ): void {
        $this->traceSpans();

        $result = self::rescue(fn() => $this->getContainer()->get($service)->{$method}());

        self::assertFinishedWith($result, $expected);
        Assert::true($this->log->problems() !== [], 'The failed export of the batch must be reported.');
    }

    /**
     * The PHP SDK takes a single trace exporter; a list is a misconfiguration that must not escape either.
     */
    #[Env('OTEL_TRACES_EXPORTER', 'otlp,memory')]
    #[DataSet(['forceFlush', false], 'forceFlush')]
    #[DataSet(['shutdown', null], 'shutdown')]
    public function exporterListNeverThrowsFromFlushOrShutdown(string $method, ?bool $expected): void
    {
        $this->traceSpans();

        $result = self::rescue(fn() => $this->provider()->{$method}());

        self::assertFinishedWith($result, $expected);
    }

    /**
     * A misconfigured exporter must not crash the worker after every request once #10 flushes in a finalizer.
     */
    #[Env('OTEL_TRACES_EXPORTER', 'bogus')]
    public function unknownExporterNeverThrowsFromFinalizerFlush(): void
    {
        $this->flushOnFinalize();
        $finalizer = $this->getContainer()->get(FinalizerInterface::class);
        $this->traceSpans();

        $thrown = self::rescue(static fn() => $finalizer->finalize(terminate: true));

        Assert::null($thrown, $thrown instanceof \Throwable ? $thrown::class . ": {$thrown->getMessage()}" : '');
    }

    /**
     * Nothing listens on port 1: the export made when the span ends fails, gets reported once, and the app goes on.
     * The short timeout only cuts each refused connect, which Windows retries for about 2 s.
     */
    #[Env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://127.0.0.1:1')]
    #[Env('OTEL_EXPORTER_OTLP_TIMEOUT', '200')]
    #[DataSet(['forceFlush'], 'forceFlush')]
    #[DataSet(['shutdown'], 'shutdown')]
    public function unreachableEndpointIsReportedAndNeverThrowsWithSimpleProcessor(string $method): void
    {
        $result = $this->tracer()->trace('lost', static fn() => 'kept');
        $finished = self::rescue(fn() => $this->provider()->{$method}());

        Assert::same($result, 'kept');
        self::assertFinishedWith($finished, null);
        $this->assertExportFailedOnce();
        $this->assertNothingArrives();
    }

    /**
     * With the batch processor the failing export runs inside forceFlush()/shutdown() themselves.
     */
    #[Env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://127.0.0.1:1')]
    #[Env('OTEL_EXPORTER_OTLP_TIMEOUT', '200')]
    #[Env('OTEL_PHP_TRACES_PROCESSOR', 'batch')]
    #[DataSet(['forceFlush'], 'forceFlush')]
    #[DataSet(['shutdown'], 'shutdown')]
    public function unreachableEndpointIsReportedAndNeverThrowsWithBatchProcessor(string $method): void
    {
        $result = $this->tracer()->trace('lost', static fn() => 'kept');
        $finished = self::rescue(fn() => $this->provider()->{$method}());

        Assert::same($result, 'kept');
        self::assertFinishedWith($finished, null);
        $this->assertExportFailedOnce();
        $this->assertNothingArrives();
    }

    /**
     * "If true, a no-op SDK implementation will be used for all telemetry signals."
     * https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#general-sdk-configuration
     */
    #[Env('OTEL_SDK_DISABLED', 'true')]
    public function disabledSdkExportsNothing(): void
    {
        $this->traceSpans();
        $this->provider()->forceFlush();

        $this->assertNothingArrives();
        $this->assertNoProblems();
    }

    /**
     * Unexported spans make ForceFlush a failure; Shutdown may report either outcome (`null`). Neither throws.
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#forceflush
     */
    private static function assertFinishedWith(mixed $result, ?bool $expected): void
    {
        $result instanceof \Throwable and Assert::fail(\sprintf('Threw %s: %s', $result::class, $result->getMessage()));
        $expected === null ? Assert::bool($result) : Assert::same($result, $expected);
    }

    /**
     * Runs a call and hands back its result, or the throwable it threw, so a test can assert on either.
     */
    private static function rescue(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * Traces {@see SPANS} and checks the traced code ran and returned normally.
     */
    private function traceSpans(): void
    {
        $tracer = $this->tracer();
        foreach (self::SPANS as $name) {
            Assert::same($tracer->trace($name, static fn(): string => "{$name}-done"), "{$name}-done");
        }
    }

    private function provider(): TracerProviderInterface
    {
        return $this->getContainer()->get(TracerProviderInterface::class);
    }

    private function processor(): SpanProcessorInterface
    {
        return $this->getContainer()->get(SpanProcessorInterface::class);
    }

    /**
     * Flushes the provider after every request, the way #10 would wire it into the finalizer.
     */
    private function flushOnFinalize(): \stdClass
    {
        $flushes = $this->finalizerFlushes = new \stdClass();
        $flushes->armed = true;
        $flushes->results = [];
        $provider = $this->provider();
        $this->getContainer()->get(FinalizerInterface::class)->addFinalizer(static function () use ($flushes, $provider): void {
            $flushes->armed and $flushes->results[] = $provider->forceFlush();
        });

        return $flushes;
    }

    private function assertNoProblems(): void
    {
        $problems = $this->log->problems();
        Assert::same($problems, [], "The SDK reported:
" . \implode("
", $problems));
    }

    private function assertExportFailedOnce(): void
    {
        $problems = $this->log->problems();
        $failures = \array_filter($problems, static fn(string $line): bool => \str_starts_with($line, 'error: Export failure'));
        Assert::count($failures, 1, "The SDK reported:\n" . \implode("\n", $problems));
    }

    private function assertNothingArrives(): void
    {
        $spans = $this->traces()->settled();
        Assert::count($spans, 0, "Received:\n{$spans}");
    }
}
