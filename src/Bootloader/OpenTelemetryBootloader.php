<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Bootloader;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\LateBindingTracerProvider;
use OpenTelemetry\API\Trace\SpanSuppression\SemanticConventionResolver;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\SpanSuppression\NoopSuppressionStrategy\NoopSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SemanticConventionSuppressionStrategy\SemanticConventionSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanKindSuppressionStrategy\SpanKindSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressionStrategy;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\FinalizerInterface;
use Spiral\Config\ConfiguratorInterface;
use Spiral\OpenTelemetry\Config\OpenTelemetryConfig;
use Spiral\OpenTelemetry\Config\ProviderMode;
use Spiral\OpenTelemetry\InstrumentationScope;
use Spiral\OpenTelemetry\Propagation\LateBindingPropagator;
use Spiral\OpenTelemetry\SystemClock;
use Spiral\OpenTelemetry\Trace\ContextFinalizer;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Spiral\OpenTelemetry\Trace\DuplicateSpanSuppressionStrategy;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Spiral\OpenTelemetry\Trace\UnsampledContext;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Bootloader\TelemetryBootloader;
use Spiral\Telemetry\ClockInterface;

final class OpenTelemetryBootloader extends Bootloader
{
    use LogsMessagesTrait;

    private const SPAN_SUPPRESSION_STRATEGY = 'OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY';
    protected const SINGLETONS = [
        TextMapPropagatorInterface::class => [self::class, 'initTextMapPropagator'],
        TracerInterface::class => [self::class, 'initTracer'],
        TracerProviderInterface::class => [self::class, 'initTraceProvider'],
        SpanExporterInterface::class => [self::class, 'initSpanExporter'],
        SpanProcessorInterface::class => [self::class, 'initSpanProcessor'],
        SamplerInterface::class => [self::class, 'initSampler'],
        ResourceInfo::class => [self::class, 'initResource'],
        ClockInterface::class => SystemClock::class,
    ];

    public function init(TelemetryBootloader $telemetry, ConfiguratorInterface $configurator): void
    {
        $telemetry->registerTracer('otel', TracerFactory::class);
        $configurator->setDefaults(OpenTelemetryConfig::CONFIG, [
            'mode' => ProviderMode::Auto->value,
            'suppress_psr15' => true,
            'flush' => [
                'every' => 1,
                'interval' => null,
            ],
        ]);
    }

    /**
     * Makes the app's provider and propagator the ones auto-instrumentation gets from `Globals`, so the
     * spans of hooks share the pipeline, and the per-request flush, of the Spiral spans. With the SDK
     * autoloader enabled, or the `globals` mode, it is the other way round: the app takes them from `Globals`.
     *
     * Done at boot, as a hook may fire before anything in the app traces; the provider registered is a
     * late-binding one, so it is still built on first use.
     */
    public function boot(
        OpenTelemetryConfig $config,
        ContainerInterface $container,
        FinalizerInterface $finalizer,
    ): void {
        if (self::globalProvider($config, report: true) !== null) {
            # Built now, so its flush is registered even if only hooks ever create spans.
            $container->get(TracerProviderInterface::class);
            $finalizer->addFinalizer(new ContextFinalizer());
            return;
        }

        # The propagator goes with the provider: auto-instrumentation extracts the incoming context with
        # `Globals::propagator()`, and the no-op one would start a new trace under which every Spiral span nests.
        # Both are taken from the container on first use, so they are the ones the app ends up binding.
        /** @psalm-suppress InternalMethod The root, so nothing active at boot stays active for good */
        $registration = Context::storage()->attach(
            Configurator::create()
                ->withTracerProvider(new LateBindingTracerProvider(
                    /** @psalm-suppress MixedReturnStatement The container binds the provider here */
                    static fn(): TracerProviderInterface => $container->get(TracerProviderInterface::class),
                ))
                ->withPropagator(new LateBindingPropagator(
                    /** @psalm-suppress MixedReturnStatement The container binds the propagator here */
                    static fn(): TextMapPropagatorInterface => $container->get(TextMapPropagatorInterface::class),
                ))
                ->storeInContext(Context::getRoot()),
        );
        $finalizer->addFinalizer(new ContextFinalizer($registration));
    }

    public function initTracer(TracerProviderInterface $tracerProvider): TracerInterface
    {
        return $tracerProvider->getTracer(InstrumentationScope::NAME, InstrumentationScope::version());
    }

    /**
     * The propagators named in `OTEL_PROPAGATORS`, by default W3C Trace Context and Baggage.
     */
    public function initTextMapPropagator(OpenTelemetryConfig $config): TextMapPropagatorInterface
    {
        return self::globalProvider($config) !== null ? Globals::propagator() : (new PropagatorFactory())->create();
    }

    /**
     * The sampler of `OTEL_TRACES_SAMPLER`; an invalid setting is reported and the SDK default applies.
     * Used only by the provider the bridge builds.
     */
    public function initSampler(): SamplerInterface
    {
        try {
            return (new SamplerFactory())->create();
        } catch (\Throwable $e) {
            self::logWarning('Unable to create sampler', ['exception' => $e]);

            return new ParentBased(new AlwaysOnSampler());
        }
    }

    /**
     * The resource of `OTEL_RESOURCE_ATTRIBUTES`, `OTEL_SERVICE_NAME` and the detectors of `OTEL_PHP_DETECTORS`.
     * Used only by the provider the bridge builds.
     */
    public function initResource(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource();
    }

    public function initSpanProcessor(
        SpanExporterInterface $exporter,
    ): SpanProcessorInterface {
        if (Sdk::isDisabled()) {
            return NoopSpanProcessor::getInstance();
        }

        # The processor exports under the context it is built in: built under a span that is not sampled,
        # it keeps the spans hooks start for the exporter's own requests from being exported in turn.
        return UnsampledContext::run(static fn(): SpanProcessorInterface => (new SpanProcessorFactory())->create($exporter));
    }

    public function initSpanExporter(): SpanExporterInterface
    {
        return new DeferredSpanExporter(
            new ExporterFactory(),
        );
    }

    /**
     * Built on first use, so finalizing an app that never traced builds nothing. From then on the
     * provider is flushed when requests or jobs end, as `flush` sets, and shut down with the app; otherwise
     * the batch processor never exports a partial batch.
     *
     * Taken from `Globals`, the provider is only flushed: its owner shuts it down. Built here, it takes the
     * sampler and the resource from the container, so an app can bind its own.
     */
    public function initTraceProvider(
        FinalizerInterface $finalizer,
        ContainerInterface $container,
        OpenTelemetryConfig $config,
    ): TracerProviderInterface {
        $every = $config->getFlushEvery();
        $interval = $config->getFlushInterval();
        $provider = self::globalProvider($config);
        if ($provider !== null) {
            $finalizer->addFinalizer(new TracerProviderFinalizer($provider, $container, false, $every, $interval));

            return $provider;
        }

        if (Sdk::isDisabled()) {
            return new NoopTracerProvider();
        }

        $spanProcessor = $container->get(SpanProcessorInterface::class);
        \assert($spanProcessor instanceof SpanProcessorInterface);
        $sampler = $container->get(SamplerInterface::class);
        \assert($sampler instanceof SamplerInterface);
        $resource = $container->get(ResourceInfo::class);
        \assert($resource instanceof ResourceInfo);

        $suppression = self::spanSuppressionStrategy();
        $provider = new TracerProvider(
            $spanProcessor,
            $sampler,
            $resource,
            spanSuppressionStrategy: $config->suppressPsr15()
                ? new DuplicateSpanSuppressionStrategy($suppression)
                : $suppression,
        );
        $providerFinalizer = new TracerProviderFinalizer($provider, $container, true, $every, $interval);
        # At exit PHP runs shutdown functions before the kernel destructor calls finalize(true), and after
        # a fatal error only them. The reference is weak: an app already destroyed is not shut down twice.
        # @infection-ignore-all Only the tests of process exit see it, and they run the unmutated src.
        ShutdownHandler::register($providerFinalizer->shutdown(...));
        # @infection-ignore-all Killed only by the tests of process exit, which run the unmutated src.
        $finalizer->addFinalizer($providerFinalizer);

        return $provider;
    }

    /**
     * The strategy `OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY` names, as the SDK reads it from 1.15:
     * `spankind`, `semconv` or `none`, the default.
     */
    private static function spanSuppressionStrategy(): SpanSuppressionStrategy
    {
        try {
            $name = Configuration::has(self::SPAN_SUPPRESSION_STRATEGY)
                ? \strtolower(\trim(Configuration::getString(self::SPAN_SUPPRESSION_STRATEGY, 'none')))
                : 'none';
        } catch (\Throwable $e) {
            self::logWarning('Unable to read the span suppression strategy, none is used', ['exception' => $e]);
            $name = 'none';
        }

        if (!\in_array($name, ['none', 'spankind', 'semconv'], true)) {
            self::logWarning(\sprintf('Unknown span suppression strategy "%s", none is used', $name));
            $name = 'none';
        }

        return match ($name) {
            'spankind' => new SpanKindSuppressionStrategy(),
            'semconv' => new SemanticConventionSuppressionStrategy(ServiceLoader::load(SemanticConventionResolver::class)),
            default => new NoopSuppressionStrategy(),
        };
    }

    /**
     * The SDK provider in `Globals` the app takes, or null when the bridge builds its own. In the `auto`
     * mode it is taken only when the SDK autoloader has built it.
     *
     * The autoloader decides when Composer loads, from the process env and ini, before the app reads its
     * `.env`: `OTEL_PHP_AUTOLOAD_ENABLED` seen only by the app leaves `Globals` without an SDK provider, and
     * the bridge then builds its own, reporting the setting it cannot follow.
     */
    private static function globalProvider(OpenTelemetryConfig $config, bool $report = false): ?TracerProviderInterface
    {
        $mode = $config->getMode();
        if ($mode === ProviderMode::Bridge || $mode === ProviderMode::Auto && !self::sdkAutoloaded()) {
            return null;
        }

        $provider = Globals::tracerProvider();
        if ($provider instanceof TracerProviderInterface) {
            return $provider;
        }

        if ($report && !Sdk::isDisabled()) {
            self::logWarning($mode === ProviderMode::Auto
                ? 'OTEL_PHP_AUTOLOAD_ENABLED is set, but the SDK autoloader has not configured the global tracer'
                    . ' provider; set it in the process environment or php.ini. The bridge builds its own provider.'
                : 'The opentelemetry.mode is "globals", but Globals has no SDK tracer provider.'
                    . ' The bridge builds its own provider.');
        }

        return null;
    }

    /**
     * Whether the SDK autoloader configures the global SDK, `OTEL_PHP_AUTOLOAD_ENABLED`.
     */
    private static function sdkAutoloaded(): bool
    {
        return Configuration::getBoolean(Variables::OTEL_PHP_AUTOLOAD_ENABLED);
    }
}
