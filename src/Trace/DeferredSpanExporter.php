<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Creates the exporter on first use and remembers the outcome: an exporter, `null` for
 * `OTEL_TRACES_EXPORTER=none`, which makes every call a successful no-op, or the factory error,
 * which fails every export and makes flush and shutdown report `false`.
 */
final class DeferredSpanExporter implements SpanExporterInterface
{
    private bool $resolved = false;
    private ?SpanExporterInterface $exporter = null;
    private ?\Throwable $error = null;

    public function __construct(
        private readonly ExporterFactory $exporterFactory,
    ) {}

    /**
     * @deprecated Not part of {@see SpanExporterInterface} and always throws; will be removed in the next major version.
     */
    public static function fromConnectionString(string $endpointUrl, string $name, string $args): void
    {
        throw new \Exception('Method is not supported.');
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion ErrorFuture declares no template, so it can't be FutureInterface<bool>
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $this->resolve();

        return match (true) {
            $this->exporter !== null => $this->exporter->export($batch, $cancellation),
            $this->error !== null => new ErrorFuture($this->error),
            default => new CompletedFuture(true),
        };
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->resolve();

        return $this->exporter?->shutdown($cancellation) ?? $this->error === null;
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        $this->resolve();

        return $this->exporter?->forceFlush($cancellation) ?? $this->error === null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;
        try {
            $this->exporter = $this->exporterFactory->create();
        } catch (\Throwable $e) {
            $this->error = $e;
        }
    }
}
