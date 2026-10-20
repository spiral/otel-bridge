<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Tests\Support\TestEnv;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `OTEL_PHP_AUTOLOAD_ENABLED=true`: the app takes the provider and propagator the SDK autoloader put in
 * `Globals`, simulated here by a context that holds them, as the autoloader runs only when Composer loads.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
final class SdkAutoloaderTest extends TestCase
{
    /**
     * Flushed at the end of each request and on termination, never shut down: the SDK does it at exit.
     */
    public function appUsesTheGlobalProviderWithoutOwningIt(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(BatchSpanProcessor::builder($exporter)->build());
        $propagator = $this->getContainer()->get(TextMapPropagatorInterface::class);
        $scope = Context::storage()->attach(
            Configurator::create()->withTracerProvider($provider)->withPropagator($propagator)->storeInContext(),
        );

        try {
            [$appProvider, $appPropagator, $exported] = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true'],
                function () use ($exporter): array {
                    $app = $this->makeApp();
                    $container = $app->getContainer();
                    $appProvider = $container->get(TracerProviderInterface::class);
                    $appProvider->getTracer('test')->spanBuilder('request')->startSpan()->end();
                    $container->get(FinalizerInterface::class)->finalize(false);
                    $exported = \count($exporter->getSpans());
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return [$appProvider, $container->get(TextMapPropagatorInterface::class), $exported];
                },
            );
            $provider->getTracer('test')->spanBuilder('after terminate')->startSpan()->end();
            $provider->forceFlush();
        } finally {
            $scope->detach();
        }

        Assert::same($appProvider, $provider);
        Assert::same($appPropagator, $propagator);
        Assert::same($exported, 1);
        Assert::count($exporter->getSpans(), 2);
    }

    /**
     * The autoloader decides when Composer loads: a setting only the app sees cannot be followed.
     */
    public function settingTheAutoloaderMissedIsReportedAndTheBridgeBuildsItsProvider(): void
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
            [$provider, $global] = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true', 'OTEL_TRACES_EXPORTER' => 'none'],
                function (): array {
                    $container = $this->makeApp()->getContainer();
                    $result = [$container->get(TracerProviderInterface::class), Globals::tracerProvider()];
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return $result;
                },
            );
        } finally {
            Logging::reset();
        }

        Assert::instanceOf($provider, TracerProvider::class);
        Assert::notSame($global, $provider);
        Assert::same($log->entries, [
            'warning: OTEL_PHP_AUTOLOAD_ENABLED is set, but the SDK autoloader has not configured the global tracer'
            . ' provider; set it in the process environment or php.ini. The bridge builds its own provider.',
        ]);
    }
}
