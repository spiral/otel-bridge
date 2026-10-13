<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

final class DeferredSpanExporter implements SpanExporterInterface
{
    private ?SpanExporterInterface $exporter = null;

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
        $exporter = $this->getExporter();
        if ($exporter !== null) {
            return $exporter->export($batch, $cancellation);
        }

        return new ErrorFuture(new \BadMethodCallException('Exporter is not initialized.'));
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return (bool) $this->getExporter()?->shutdown($cancellation);
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return (bool) $this->getExporter()?->forceFlush($cancellation);
    }

    private function getExporter(): ?SpanExporterInterface
    {
        if ($this->exporter === null) {
            $this->exporter = $this->exporterFactory->create();
        }

        return $this->exporter;
    }
}
