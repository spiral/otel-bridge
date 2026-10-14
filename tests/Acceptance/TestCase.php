<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\Boot\Bootloader\ConfigurationBootloader;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\OtelEnvInterceptor;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanCollector;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * A Spiral app with the `otel` telemetry driver exporting over OTLP/HTTP JSON to the suite's Trap.
 *
 * The endpoint and a per-test `OTEL_SERVICE_NAME` come from {@see OtelEnvInterceptor}; `#[Env]` overrides
 * any of the values here.
 */
abstract class TestCase extends \Spiral\Testing\TestCase
{
    public const ENV = [
        'TELEMETRY_DRIVER' => 'otel',
        'OTEL_TRACES_EXPORTER' => 'otlp',
        'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/json',
        'OTEL_PHP_TRACES_PROCESSOR' => 'simple',
    ];

    public function rootDirectory(): string
    {
        return __DIR__ . '/..';
    }

    public function defineBootloaders(): array
    {
        return [
            ConfigurationBootloader::class,
            OpenTelemetryBootloader::class,
        ];
    }

    protected function traces(): SpanCollector
    {
        return $this->getContainer()->get(SpanCollector::class);
    }

    protected function tracer(): TracerInterface
    {
        return $this->getContainer()->get(TracerInterface::class);
    }

    /**
     * @param array<string, string|list<string>> $context Incoming propagation headers.
     */
    protected function tracerWithContext(array $context): TracerInterface
    {
        return $this->getContainer()->get(TracerFactoryInterface::class)->make($context);
    }
}
