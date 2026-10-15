<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
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
    #[DataSet([TextMapPropagatorInterface::class, TraceContextPropagator::class], 'propagator')]
    public function bindsOpenTelemetryService(string $alias, string $class): void
    {
        $this->assertContainerBound($alias, $class);
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

        [$processor, $provider] = self::withProcessEnv(['OTEL_SDK_DISABLED' => 'true'], static function () use ($bootloader): array {
            $processor = $bootloader->initSpanProcessor($bootloader->initSpanExporter());

            return [$processor, $bootloader->initTraceProvider($processor)];
        });

        Assert::instanceOf($processor, NoopSpanProcessor::class);
        Assert::instanceOf($provider, NoopTracerProvider::class);
        Assert::true($provider->forceFlush());
        Assert::true($provider->shutdown());
    }

    public function samplerComesFromEnv(): void
    {
        $bootloader = new OpenTelemetryBootloader();

        $span = self::withProcessEnv(
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
                $this->entries[] = "{$level}: {$message}";
            }
        };
        Logging::setLogWriter($log);

        try {
            $span = self::withProcessEnv(
                ['OTEL_TRACES_SAMPLER' => 'bogus'],
                static fn() => self::startSpan($bootloader),
            );
        } finally {
            Logging::reset();
        }

        Assert::true($span->getContext()->isSampled());
        Assert::same($log->entries, ['warning: Unable to create sampler']);
    }

    private static function startSpan(OpenTelemetryBootloader $bootloader): SpanInterface
    {
        return $bootloader
            ->initTraceProvider(NoopSpanProcessor::getInstance())
            ->getTracer('test')
            ->spanBuilder('root')
            ->startSpan();
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
