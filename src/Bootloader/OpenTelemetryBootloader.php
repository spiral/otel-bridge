<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Bootloader;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\EnvironmentInterface;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\SystemClock;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Bootloader\TelemetryBootloader;
use Spiral\Telemetry\ClockInterface;

final class OpenTelemetryBootloader extends Bootloader
{
    use LogsMessagesTrait;

    protected const SINGLETONS = [
        TextMapPropagatorInterface::class => [self::class, 'initTextMapPropagator'],
        TracerInterface::class => [self::class, 'initTracer'],
        TracerProviderInterface::class => [self::class, 'initTraceProvider'],
        SpanExporterInterface::class => [self::class, 'initSpanExporter'],
        SpanProcessorInterface::class => [self::class, 'initSpanProcessor'],
        ClockInterface::class => SystemClock::class,
    ];

    public function init(TelemetryBootloader $telemetry): void
    {
        $telemetry->registerTracer('otel', TracerFactory::class);
    }

    public function initTracer(
        EnvironmentInterface $env,
        TracerProviderInterface $tracerProvider,
    ): TracerInterface {
        return $tracerProvider->getTracer(
            (string) $env->get('OTEL_SERVICE_NAME', 'Spiral Framework'),
        );
    }

    /**
     * The propagators named in `OTEL_PROPAGATORS`, by default W3C Trace Context and Baggage.
     */
    public function initTextMapPropagator(): TextMapPropagatorInterface
    {
        return (new PropagatorFactory())->create();
    }

    public function initSpanProcessor(
        SpanExporterInterface $exporter,
    ): SpanProcessorInterface {
        if (Sdk::isDisabled()) {
            return NoopSpanProcessor::getInstance();
        }

        return (new SpanProcessorFactory())->create($exporter);
    }

    public function initSpanExporter(): SpanExporterInterface
    {
        return new DeferredSpanExporter(
            new ExporterFactory(),
        );
    }

    /**
     * Built on first use, so finalizing an app that never traced builds nothing. From then on the
     * provider is flushed when each request or job ends and shut down with the app; otherwise the
     * batch processor never exports a partial batch.
     */
    public function initTraceProvider(
        SpanProcessorInterface $spanProcessor,
        FinalizerInterface $finalizer,
        ContainerInterface $container,
    ): TracerProviderInterface {
        if (Sdk::isDisabled()) {
            return new NoopTracerProvider();
        }

        try {
            $sampler = (new SamplerFactory())->create();
        } catch (\Throwable $e) {
            self::logWarning('Unable to create sampler', ['exception' => $e]);
            $sampler = null;
        }

        $provider = new TracerProvider($spanProcessor, $sampler);
        $providerFinalizer = new TracerProviderFinalizer($provider, $container);
        # At exit PHP runs shutdown functions before the kernel destructor calls finalize(true), and after
        # a fatal error only them. The reference is weak: an app already destroyed is not shut down twice.
        # @infection-ignore-all Only the tests of process exit see it, and they run the unmutated src.
        ShutdownHandler::register($providerFinalizer->shutdown(...));
        # @infection-ignore-all Killed only by the tests of process exit, which run the unmutated src.
        $finalizer->addFinalizer($providerFinalizer);

        return $provider;
    }
}
