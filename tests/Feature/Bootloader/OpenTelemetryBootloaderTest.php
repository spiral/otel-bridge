<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\Finalizer;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Tests\Support\TestEnv;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Config\TelemetryConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(OpenTelemetryBootloader::class)]
final class OpenTelemetryBootloaderTest extends TestCase
{
    #[DataSet([TracerInterface::class, Tracer::class], 'tracer')]
    #[DataSet([TracerProviderInterface::class, TracerProvider::class], 'tracer provider')]
    #[DataSet([SpanExporterInterface::class, DeferredSpanExporter::class], 'span exporter')]
    #[DataSet([TextMapPropagatorInterface::class, MultiTextMapPropagator::class], 'propagator')]
    public function bindsOpenTelemetryService(string $alias, string $class): void
    {
        $this->assertContainerBound($alias, $class);
    }

    public function propagatorIsSingleton(): void
    {
        $container = $this->getContainer();

        Assert::same($container->get(TextMapPropagatorInterface::class), $container->get(TextMapPropagatorInterface::class));
    }

    #[DataSet(['tracecontext', TraceContextPropagator::class], 'one')]
    #[DataSet(['tracecontext,baggage', MultiTextMapPropagator::class], 'several')]
    #[DataSet(['none', NoopTextMapPropagator::class], 'none')]
    public function propagatorComesFromEnv(string $propagators, string $class): void
    {
        $bootloader = new OpenTelemetryBootloader();

        $propagator = TestEnv::withProcessEnv(
            ['OTEL_PROPAGATORS' => $propagators],
            static fn() => $bootloader->initTextMapPropagator(),
        );

        Assert::instanceOf($propagator, $class);
    }

    public function registersOtelTracerDriver(): void
    {
        $driver = $this->getConfig(TelemetryConfig::CONFIG)['drivers']['otel'] ?? null;

        Assert::same($driver, TracerFactory::class);
    }

    /**
     * "If true, a no-op SDK implementation will be used for all telemetry signals."
     * https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#general-sdk-configuration
     */
    public function disabledSdkGetsNoopProcessorAndProvider(): void
    {
        $bootloader = new OpenTelemetryBootloader();
        $finalizer = new Finalizer();

        [$processor, $provider] = TestEnv::withProcessEnv(['OTEL_SDK_DISABLED' => 'true'], static function () use ($bootloader, $finalizer): array {
            $processor = $bootloader->initSpanProcessor($bootloader->initSpanExporter());

            return [$processor, $bootloader->initTraceProvider($finalizer, new Container())];
        });
        $finalizer->finalize(false);
        $finalizer->finalize(true);

        Assert::instanceOf($processor, NoopSpanProcessor::class);
        Assert::instanceOf($provider, NoopTracerProvider::class);
        Assert::true($provider->forceFlush());
        Assert::true($provider->shutdown());
    }

    /**
     * `OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY` as the SDK reads it from 1.15: by default nothing is
     * suppressed, `spankind` and `semconv` suppress a SERVER span inside a SERVER span.
     */
    #[DataSet([[], false], 'default')]
    #[DataSet([['OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY' => 'none'], false], 'none')]
    #[DataSet([['OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY' => ' SpanKind '], true], 'spankind')]
    #[DataSet([['OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY' => 'semconv'], true], 'semconv')]
    public function spanSuppressionComesFromEnv(array $env, bool $suppressed): void
    {
        $bootloader = new OpenTelemetryBootloader();

        [$outer, $inner] = TestEnv::withProcessEnv($env, static fn(): array => self::nestedServerSpans($bootloader));

        Assert::same($inner->getContext()->getSpanId() === $outer->getContext()->getSpanId(), $suppressed);
    }

    public function unknownSpanSuppressionStrategyIsReported(): void
    {
        $bootloader = new OpenTelemetryBootloader();
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
            [$outer, $inner] = TestEnv::withProcessEnv(
                ['OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY' => 'bogus'],
                static fn(): array => self::nestedServerSpans($bootloader),
            );
        } finally {
            Logging::reset();
        }

        Assert::notSame($inner->getContext()->getSpanId(), $outer->getContext()->getSpanId());
        Assert::same($log->entries, ['warning: Unknown span suppression strategy "bogus", none is used']);
    }

    public function samplerComesFromEnv(): void
    {
        $bootloader = new OpenTelemetryBootloader();

        $span = TestEnv::withProcessEnv(
            ['OTEL_TRACES_SAMPLER' => 'always_off'],
            static fn() => self::startSpan($bootloader),
        );

        Assert::false($span->getContext()->isSampled());
    }

    /**
     * An invalid setting is reported and the default applies, as in the SDK's own `TracerProviderFactory`.
     */
    public function unknownSamplerIsReportedAndDefaultIsKept(): void
    {
        $bootloader = new OpenTelemetryBootloader();
        $log = new class implements LogWriterInterface {
            /** @var list<string> */
            public array $entries = [];

            #[\Override]
            public function write($level, string $message, array $context): void
            {
                $this->entries[] = "{$level}: {$message}" . (($context['exception'] ?? null) instanceof \Throwable ? ' [exception]' : '');
            }
        };
        Logging::setLogWriter($log);

        try {
            $span = TestEnv::withProcessEnv(
                ['OTEL_TRACES_SAMPLER' => 'bogus'],
                static fn() => self::startSpan($bootloader),
            );
        } finally {
            Logging::reset();
        }

        Assert::true($span->getContext()->isSampled());
        Assert::same($log->entries, ['warning: Unable to create sampler [exception]']);
    }

    /**
     * @return array{SpanInterface, SpanInterface} A SERVER span and one started inside it.
     */
    private static function nestedServerSpans(OpenTelemetryBootloader $bootloader): array
    {
        $tracer = $bootloader
            ->initTraceProvider(new Finalizer(), self::noopProcessorContainer())
            ->getTracer('test');
        $outer = $tracer->spanBuilder('outer')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
        $scope = $outer->activate();

        try {
            return [$outer, $tracer->spanBuilder('inner')->setSpanKind(SpanKind::KIND_SERVER)->startSpan()];
        } finally {
            $scope->detach();
        }
    }

    private static function noopProcessorContainer(): Container
    {
        $container = new Container();
        $container->bindSingleton(SpanProcessorInterface::class, NoopSpanProcessor::getInstance());

        return $container;
    }

    private static function startSpan(OpenTelemetryBootloader $bootloader): SpanInterface
    {
        return $bootloader
            ->initTraceProvider(new Finalizer(), self::noopProcessorContainer())
            ->getTracer('test')
            ->spanBuilder('root')
            ->startSpan();
    }
}
