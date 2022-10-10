<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Bootloader;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Otlp\SpanConverter;
use OpenTelemetry\SDK\Common\Dsn\Parser;
use OpenTelemetry\SDK\Common\Dsn\ParserInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\EnvironmentInterface;
use Spiral\Config\ConfiguratorInterface;
use Spiral\OpenTelemetry\Config\OpenTelemetryConfig;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\Bootloader\TelemetryBootloader;

class OpenTelemetryBootloader extends Bootloader
{
    protected const BINDINGS = [
        TracerInterface::class => [self::class, 'initTracer'],
        TracerProviderInterface::class => [self::class, 'initTraceProvider'],
        SpanExporterInterface::class => [self::class, 'initSpanExporter'],
        ParserInterface::class => Parser::class,
        TextMapPropagatorInterface::class => [self::class, 'initTextMapPropagator'],
    ];

    protected const SINGLETONS = [
        SpanProcessorInterface::class => [self::class, 'initSpanProcessor'],
    ];

    public function __construct(
        private readonly ConfiguratorInterface $config
    ) {
    }

    public function init(EnvironmentInterface $env, TelemetryBootloader $telemetry): void
    {
        $this->initConfig($env);
        $telemetry->registerTracer('otel', Tracer::class);
    }

    public function initTracer(
        EnvironmentInterface $env,
        TracerProviderInterface $tracerProvider
    ): TracerInterface {
        return $tracerProvider->getTracer(
            $env->get('APP_NAME', 'Spiral Framework')
        );
    }

    public function initTextMapPropagator(): TextMapPropagatorInterface
    {
        return TraceContextPropagator::getInstance();
    }

    public function initSpanProcessor(
        SpanExporterInterface $exporter,
        SpanConverter $spanConverter,
        ContainerInterface $container,
    ): SpanProcessorInterface {
        return (new SpanProcessorFactory())->fromEnvironment($exporter);
        //return new StatelessSpanProcessor($container, $spanConverter);
    }

    public function initSpanExporter(
        ContainerInterface $container,
        ParserInterface $parser,
        EnvironmentInterface $env
    ): SpanExporterInterface {
        return new DeferredSpanExporter(
            $container,
            new ExporterFactory(
                $env->get('APP_NAME', 'Spiral Framework'),
                $parser
            )
        );
    }

    public function initTraceProvider(
        SpanProcessorInterface $spanProcessor
    ): TracerProviderInterface {
        return new TracerProvider($spanProcessor);
    }

    private function initConfig(EnvironmentInterface $env): void
    {
        $this->config->setDefaults(
            OpenTelemetryConfig::CONFIG,
            [
                'name' => $env->get('APP_NAME'),
                'dsn' => $env->get('OTEL_DSN'),
            ]
        );
    }
}
