<?php

/**
 * Builds the tracer provider the way the bootloader does, ends a span with the batch processor and exits
 * without finalize(true), as after a fatal error. What the exporter and the exception reporter get is
 * written to stdout.
 *
 * Usage: php exit-without-finalize.php [ok|throw]: `throw` makes the exporter's shutdown throw.
 */

declare(strict_types=1);

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use Spiral\Boot\Finalizer;
use Spiral\Core\Container;
use Spiral\Exceptions\ExceptionReporterInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;

require __DIR__ . '/../../vendor/autoload.php';

$throw = ($argv[1] ?? 'ok') === 'throw';

$exporter = new class($throw) implements SpanExporterInterface {
    public function __construct(
        private readonly bool $throw,
    ) {}

    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        foreach ($batch as $span) {
            echo "exported: {$span->getName()}\n";
        }

        return new CompletedFuture(true);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        echo "shutdown\n";

        return $this->throw ? throw new \RuntimeException('Exporter failed') : true;
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
};

$container = new Container();
$container->bindSingleton(ExceptionReporterInterface::class, new class implements ExceptionReporterInterface {
    #[\Override]
    public function report(\Throwable $exception): void
    {
        echo "reported: {$exception->getMessage()}\n";
    }
});

$finalizer = new Finalizer();
$provider = (new OpenTelemetryBootloader())->initTraceProvider(
    BatchSpanProcessor::builder($exporter)->build(),
    $finalizer,
    $container,
);
$provider->getTracer('test')->spanBuilder('unfinished request')->startSpan()->end();
echo "exit\n";
