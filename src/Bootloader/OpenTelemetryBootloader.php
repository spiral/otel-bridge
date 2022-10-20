<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Bootloader;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Common\Dsn\Parser;
use OpenTelemetry\SDK\Common\Dsn\ParserInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\EnvironmentInterface;
use Spiral\OpenTelemetry\SystemClock;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Spiral\OpenTelemetry\TracerFactory;
use Spiral\Telemetry\Bootloader\TelemetryBootloader;
use Spiral\Telemetry\ClockInterface;

final class OpenTelemetryBootloader extends Bootloader
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
        ClockInterface::class => SystemClock::class
    ];

    public function init(EnvironmentInterface $env, TelemetryBootloader $telemetry): void
    {
        $telemetry->registerTracer('otel', TracerFactory::class);
    }

    public function initTracer(
        EnvironmentInterface $env,
        TracerProviderInterface $tracerProvider
    ): TracerInterface {
        return $tracerProvider->getTracer(
            $env->get('OTEL_SERVICE_NAME', 'Spiral Framework')
        );
    }

    public function initTextMapPropagator(): TextMapPropagatorInterface
    {
        return TraceContextPropagator::getInstance();
    }

    public function initSpanProcessor(
        SpanExporterInterface $exporter,
    ): SpanProcessorInterface {
        return (new SpanProcessorFactory())->fromEnvironment($exporter);
    }

    public function initSpanExporter(
        ParserInterface $parser,
        EnvironmentInterface $env
    ): SpanExporterInterface {
        return new DeferredSpanExporter(
            new ExporterFactory(
                $env->get('OTEL_SERVICE_NAME', 'Spiral Framework'),
                $parser
            )
        );
    }

    public function initTraceProvider(
        SpanProcessorInterface $spanProcessor
    ): TracerProviderInterface {
        return new TracerProvider($spanProcessor);
    }
}
