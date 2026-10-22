<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Tests\Support\TestEnv;
use Spiral\OpenTelemetry\Trace\ContextFinalizer;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `OTEL_PHP_AUTOLOAD_ENABLED=true`: the app takes the provider and propagator the SDK autoloader put in
 * `Globals`, simulated here by a context that holds them, as the autoloader runs only when Composer loads.
 * Each test boots its own app, so that the boot itself runs in the test.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
#[Covers(ContextFinalizer::class)]
final class SdkAutoloaderTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    /**
     * Spans of hooks are flushed at the end of each request although nothing in the app traced; the
     * provider is flushed on termination too, but never shut down: the SDK does it at exit.
     */
    public function appFlushesTheGlobalProviderWithoutOwningIt(): void
    {
        $scope = $this->registerGlobalProvider();

        try {
            [$globalAtBoot, $appProvider, $flushed] = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true'],
                function (): array {
                    $container = $this->makeApp()->getContainer();
                    $globalAtBoot = Globals::tracerProvider();
                    Globals::tracerProvider()->getTracer('hook')->spanBuilder('hook')->startSpan()->end();
                    $container->get(FinalizerInterface::class)->finalize(false);
                    $flushed = \count($this->exporter->getSpans());
                    $appProvider = $container->get(TracerProviderInterface::class);
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return [$globalAtBoot, $appProvider, $flushed];
                },
            );
            $this->provider->getTracer('test')->spanBuilder('after terminate')->startSpan()->end();
            $this->provider->forceFlush();
        } finally {
            $scope->detach();
        }

        Assert::same($globalAtBoot, $this->provider);
        Assert::same($appProvider, $this->provider);
        Assert::same($flushed, 1);
        Assert::count($this->exporter->getSpans(), 2);
    }

    public function appUsesTheGlobalPropagator(): void
    {
        $propagator = $this->getContainer()->get(TextMapPropagatorInterface::class);
        $scope = $this->registerGlobalProvider($propagator);

        try {
            $appPropagator = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true'],
                function (): TextMapPropagatorInterface {
                    $container = $this->makeApp()->getContainer();
                    $appPropagator = $container->get(TextMapPropagatorInterface::class);
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return $appPropagator;
                },
            );
        } finally {
            $scope->detach();
        }

        Assert::same($appPropagator, $propagator);
    }

    public function spanLeftActiveAfterRequestIsReported(): void
    {
        $scope = $this->registerGlobalProvider();

        try {
            $log = TestEnv::withProcessEnv(['OTEL_PHP_AUTOLOAD_ENABLED' => 'true'], function (): array {
                $container = $this->makeApp()->getContainer();
                $span = $this->provider->getTracer('test')->spanBuilder('leaked')->startSpan();
                $spanScope = $span->activate();
                try {
                    return self::logOf(static fn() => $container->get(FinalizerInterface::class)->finalize(false));
                } finally {
                    $spanScope->detach();
                    $span->end();
                    $container->get(FinalizerInterface::class)->finalize(true);
                }
            });
        } finally {
            $scope->detach();
        }

        Assert::count($log, 1);
        Assert::string($log[0])->startsWith('warning: A span is still active after the request or job has ended');
    }

    /**
     * Without the setting, a provider found in `Globals` is not the app's.
     */
    public function globalProviderIsIgnoredWithoutTheSetting(): void
    {
        $scope = $this->registerGlobalProvider();

        try {
            [$appProvider, $log] = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => null, 'OTEL_TRACES_EXPORTER' => 'none'],
                function (): array {
                    $container = null;
                    $log = self::logOf(function () use (&$container): void {
                        $container = $this->makeApp()->getContainer();
                    });
                    \assert($container !== null);
                    $appProvider = $container->get(TracerProviderInterface::class);
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return [$appProvider, $log];
                },
            );
        } finally {
            $scope->detach();
        }

        Assert::notSame($appProvider, $this->provider);
        Assert::same($log, []);
    }

    /**
     * The autoloader decides when Composer loads: a setting only the app sees cannot be followed. It is
     * reported once, at boot.
     */
    public function settingTheAutoloaderMissedIsReportedAndTheBridgeBuildsItsProvider(): void
    {
        [$provider, $global, $atBoot, $later] = TestEnv::withProcessEnv(
            ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true', 'OTEL_TRACES_EXPORTER' => 'none'],
            function (): array {
                $container = null;
                $atBoot = self::logOf(function () use (&$container): void {
                    $container = $this->makeApp()->getContainer();
                });
                \assert($container !== null);
                $provider = null;
                $later = self::logOf(static function () use ($container, &$provider): void {
                    $provider = $container->get(TracerProviderInterface::class);
                    $container->get(TextMapPropagatorInterface::class);
                });
                $global = Globals::tracerProvider();
                $container->get(FinalizerInterface::class)->finalize(true);

                return [$provider, $global, $atBoot, $later];
            },
        );

        Assert::instanceOf($provider, TracerProvider::class);
        Assert::notSame($global, $provider);
        Assert::same($atBoot, [
            'warning: OTEL_PHP_AUTOLOAD_ENABLED is set, but the SDK autoloader has not configured the global tracer'
            . ' provider; set it in the process environment or php.ini. The bridge builds its own provider.',
        ]);
        Assert::same($later, []);
    }

    /**
     * @return list<string> What the SDK log got while `$call` ran.
     */
    private static function logOf(\Closure $call): array
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

    /**
     * Puts an SDK provider, and the propagator if given, in the context `Globals` reads, as the SDK
     * autoloader would.
     */
    private function registerGlobalProvider(?TextMapPropagatorInterface $propagator = null): ScopeInterface
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(BatchSpanProcessor::builder($this->exporter)->build());
        $configurator = Configurator::create()->withTracerProvider($this->provider);
        $propagator === null or $configurator = $configurator->withPropagator($propagator);

        return Context::storage()->attach($configurator->storeInContext());
    }
}
