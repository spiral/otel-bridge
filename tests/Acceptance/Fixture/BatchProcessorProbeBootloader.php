<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\EnvironmentInterface;
use Spiral\Core\BinderInterface;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;

/**
 * Counts the span exporters the app creates, to tell whether the tracing stack was instantiated at all.
 *
 * Active only when the env has `BATCH_PROCESSOR_PROBE`: it then replaces the exporter binding of
 * OpenTelemetryBootloader with a singleton of the same exporter, so the other tests see the bridge as is.
 */
final class BatchProcessorProbeBootloader extends Bootloader
{
    public int $exportersCreated = 0;

    public function boot(EnvironmentInterface $env, BinderInterface $binder): void
    {
        if (!$env->get('BATCH_PROCESSOR_PROBE')) {
            return;
        }

        $binder->bindSingleton(self::class, $this);
        $binder->bindSingleton(SpanExporterInterface::class, function (): SpanExporterInterface {
            ++$this->exportersCreated;

            return new DeferredSpanExporter(new ExporterFactory());
        });
    }
}
