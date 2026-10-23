<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Config\OpenTelemetryConfig;
use Spiral\OpenTelemetry\Propagation\LateBindingPropagator;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\BridgeConfigBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Tests\Support\TestEnv;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * `opentelemetry.mode` other than `auto`, whose cases {@see SdkAutoloaderTest} covers. The provider the SDK
 * autoloader would put in `Globals` is simulated by a context that holds it. Each test boots its own app,
 * so that the boot itself runs in the test.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(OpenTelemetryConfig::class)]
final class ProviderModeTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    #[\Override]
    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), BridgeConfigBootloader::class];
    }

    #[AfterTest]
    public function resetConfig(): void
    {
        BridgeConfigBootloader::$values = [];
    }

    /**
     * The provider and propagator in `Globals` are ignored even with the SDK autoloader enabled, and the
     * app's are registered over them.
     */
    public function bridgeModeBuildsItsOwnProviderOverTheGlobalOne(): void
    {
        BridgeConfigBootloader::$values = ['mode' => 'bridge'];
        $scope = $this->registerGlobalProvider(TraceContextPropagator::getInstance());

        try {
            [$appProvider, $appPropagator, $globalPropagator, $log] = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true', 'OTEL_TRACES_EXPORTER' => 'none'],
                function (): array {
                    $container = null;
                    $log = self::logOf(function () use (&$container): void {
                        $container = $this->makeApp()->getContainer();
                    });
                    \assert($container !== null);
                    $globalPropagator = Globals::propagator();
                    $appProvider = $container->get(TracerProviderInterface::class);
                    $appPropagator = $container->get(TextMapPropagatorInterface::class);
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return [$appProvider, $appPropagator, $globalPropagator, $log];
                },
            );
        } finally {
            $scope->detach();
        }

        Assert::instanceOf($appProvider, TracerProvider::class);
        Assert::notSame($appProvider, $this->provider);
        Assert::notSame($appPropagator, TraceContextPropagator::getInstance());
        Assert::instanceOf($globalPropagator, LateBindingPropagator::class);
        Assert::same($log, []);
    }

    /**
     * Whoever put them in `Globals`, the SDK autoloader or not, the app takes them, flushes the provider
     * at the end of each request and leaves shutting it down to its owner.
     */
    public function globalsModeTakesTheGlobalProviderWithoutTheAutoloader(): void
    {
        BridgeConfigBootloader::$values = ['mode' => 'globals'];
        $scope = $this->registerGlobalProvider(TraceContextPropagator::getInstance());

        try {
            [$appProvider, $appPropagator, $flushed] = TestEnv::withProcessEnv(
                ['OTEL_PHP_AUTOLOAD_ENABLED' => null],
                function (): array {
                    $container = $this->makeApp()->getContainer();
                    $appProvider = $container->get(TracerProviderInterface::class);
                    $appPropagator = $container->get(TextMapPropagatorInterface::class);
                    $this->provider->getTracer('hook')->spanBuilder('hook')->startSpan()->end();
                    $container->get(FinalizerInterface::class)->finalize(false);
                    $flushed = \count($this->exporter->getSpans());
                    $container->get(FinalizerInterface::class)->finalize(true);

                    return [$appProvider, $appPropagator, $flushed];
                },
            );
            $this->provider->getTracer('test')->spanBuilder('after terminate')->startSpan()->end();
            $this->provider->forceFlush();
        } finally {
            $scope->detach();
        }

        Assert::same($appProvider, $this->provider);
        Assert::same($appPropagator, TraceContextPropagator::getInstance());
        Assert::same($flushed, 1);
        Assert::count($this->exporter->getSpans(), 2);
    }

    /**
     * Reported once, at boot; the app then registers the provider it builds, as in the `bridge` mode.
     */
    public function globalsModeWithoutGlobalProviderIsReportedAndTheBridgeBuildsItsOwn(): void
    {
        BridgeConfigBootloader::$values = ['mode' => 'globals'];
        [$provider, $propagator, $atBoot, $later] = TestEnv::withProcessEnv(
            ['OTEL_PHP_AUTOLOAD_ENABLED' => null, 'OTEL_TRACES_EXPORTER' => 'none'],
            function (): array {
                $container = null;
                $atBoot = self::logOf(function () use (&$container): void {
                    $container = $this->makeApp()->getContainer();
                });
                \assert($container !== null);
                $propagator = Globals::propagator();
                $provider = null;
                $later = self::logOf(static function () use ($container, &$provider): void {
                    $provider = $container->get(TracerProviderInterface::class);
                    $container->get(TextMapPropagatorInterface::class);
                });
                $container->get(FinalizerInterface::class)->finalize(true);

                return [$provider, $propagator, $atBoot, $later];
            },
        );

        Assert::instanceOf($provider, TracerProvider::class);
        Assert::instanceOf($propagator, LateBindingPropagator::class);
        Assert::same($atBoot, [
            'warning: The opentelemetry.mode is "globals", but Globals has no SDK tracer provider.'
            . ' The bridge builds its own provider.',
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

    private function registerGlobalProvider(TextMapPropagatorInterface $propagator): ScopeInterface
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(BatchSpanProcessor::builder($this->exporter)->build());

        return Context::storage()->attach(
            Configurator::create()->withTracerProvider($this->provider)->withPropagator($propagator)->storeInContext(),
        );
    }
}
