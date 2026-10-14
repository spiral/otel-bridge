<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * {@see DeferredSpanExporter} over a factory that yields an exporter, `null` (`OTEL_TRACES_EXPORTER=none`)
 * or throws (an unknown exporter, or one whose package is not installed), and counts its calls.
 */
#[Test]
#[Covers(DeferredSpanExporter::class)]
final class ExporterLifecycleTest
{
    private const EXPORTER = 'exporter';
    private const NONE = 'none';
    private const UNKNOWN = 'unknown';
    private const UNKNOWN_MESSAGE = 'Span exporter factory not defined for: bogus';

    public function exporterFactoryIsResolvedOnce(): void
    {
        $factory = self::factory(self::EXPORTER);
        $exporter = new DeferredSpanExporter($factory);

        self::callEverything($exporter);

        Assert::same($factory->calls, 1);
    }

    #[DataSet([self::NONE], 'none')]
    #[DataSet([self::UNKNOWN], 'unknown exporter')]
    #[Skip('Known issue: the factory outcome is not memoized when it is null or throws, see https://github.com/spiral/otel-bridge/issues/21')]
    public function failedFactoryOutcomeIsResolvedOnce(string $outcome): void
    {
        $factory = self::factory($outcome);
        $exporter = new DeferredSpanExporter($factory);

        self::callEverything($exporter);

        Assert::same($factory->calls, 1);
    }

    public function exporterIsDelegatedTo(): void
    {
        $exporter = new DeferredSpanExporter(self::factory(self::EXPORTER));

        $exported = $exporter->export([])->await();
        $flushed = $exporter->forceFlush();
        $shutDown = $exporter->shutdown();

        Assert::true($exported);
        Assert::true($flushed);
        Assert::true($shutDown);
    }

    /**
     * `none` is "no exporter": nothing to export is a successful export.
     * https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#exporter-selection
     */
    #[Skip('Known issue: with OTEL_TRACES_EXPORTER=none export() fails with "Exporter is not initialized.", see https://github.com/spiral/otel-bridge/issues/21')]
    public function noneExportSucceeds(): void
    {
        $exporter = new DeferredSpanExporter(self::factory(self::NONE));

        $first = self::rescue(static fn() => $exporter->export([])->await());
        $second = self::rescue(static fn() => $exporter->export([])->await());

        Assert::same($first, true);
        Assert::same($second, true);
    }

    #[DataSet(['forceFlush'], 'forceFlush')]
    #[DataSet(['shutdown'], 'shutdown')]
    #[Skip('Known issue: with OTEL_TRACES_EXPORTER=none forceFlush() and shutdown() return false, see https://github.com/spiral/otel-bridge/issues/21')]
    public function noneFlushAndShutdownSucceed(string $method): void
    {
        $exporter = new DeferredSpanExporter(self::factory(self::NONE));

        $result = $exporter->{$method}();

        Assert::true($result);
    }

    /**
     * A misconfiguration is reported on export, but must never escape flush or shutdown.
     * https://opentelemetry.io/docs/specs/otel/error-handling/#basic-error-handling-principles
     */
    #[DataSet(['forceFlush'], 'forceFlush')]
    #[DataSet(['shutdown'], 'shutdown')]
    #[Skip('Known issue: an unknown exporter makes forceFlush() and shutdown() throw, see https://github.com/spiral/otel-bridge/issues/21')]
    public function unknownExporterNeverThrowsFromFlushOrShutdown(string $method): void
    {
        $exporter = new DeferredSpanExporter(self::factory(self::UNKNOWN));

        $result = self::rescue(static fn() => $exporter->{$method}());

        $result instanceof \Throwable and Assert::fail(\sprintf('Threw %s: %s', $result::class, $result->getMessage()));
        Assert::bool($result);
    }

    /**
     * Remembering the failure must not turn it into a silent no-op: every export keeps failing with the cause.
     */
    public function unknownExporterFailsEveryExport(): void
    {
        $exporter = new DeferredSpanExporter(self::factory(self::UNKNOWN));

        $first = self::rescue(static fn() => $exporter->export([])->await());
        $second = self::rescue(static fn() => $exporter->export([])->await());

        self::assertExportFailedWithCause($first);
        self::assertExportFailedWithCause($second);
    }

    /**
     * A failed export is either a `false` result or a throwable from the future that keeps the factory error.
     */
    private static function assertExportFailedWithCause(mixed $result): void
    {
        if (!$result instanceof \Throwable) {
            Assert::same($result, false);
            return;
        }

        $messages = [];
        for ($e = $result; $e !== null; $e = $e->getPrevious()) {
            $messages[] = $e->getMessage();
        }

        Assert::contains($messages, self::UNKNOWN_MESSAGE, \implode(' <- ', $messages));
    }

    /**
     * Everything a span processor calls on its exporter over a process: three exports, a flush and a shutdown.
     */
    private static function callEverything(SpanExporterInterface $exporter): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::rescue(static fn() => $exporter->export([])->await());
        }

        self::rescue(static fn() => $exporter->forceFlush());
        self::rescue(static fn() => $exporter->shutdown());
    }

    /**
     * @return ExporterFactory&object{calls: int}
     */
    private static function factory(string $outcome): ExporterFactory
    {
        return new class($outcome, self::UNKNOWN_MESSAGE) extends ExporterFactory {
            public int $calls = 0;

            public function __construct(
                private readonly string $outcome,
                private readonly string $unknownMessage,
            ) {}

            #[\Override]
            public function create(): ?SpanExporterInterface
            {
                ++$this->calls;

                return match ($this->outcome) {
                    'exporter' => new InMemoryExporter(),
                    'none' => null,
                    default => throw new \RuntimeException($this->unknownMessage),
                };
            }
        };
    }

    private static function rescue(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            return $e;
        }
    }
}
