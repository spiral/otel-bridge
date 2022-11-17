<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

final class DeferredSpanExporter implements SpanExporterInterface
{
    private ?SpanExporterInterface $exporter = null;

    public function __construct(
        private readonly ExporterFactory $exporterFactory,
    ) {
    }

    public static function fromConnectionString(string $endpointUrl, string $name, string $args)
    {
        throw new \Exception('Method is not supported.');
    }

    /**
     * @psalm-suppress InvalidReturnType,InvalidReturnStatement
     */
    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->getExporter() !== null) {
            return $this->getExporter()->export($spans, $cancellation);
        }

        return new CompletedFuture(SpanExporterInterface::STATUS_FAILED_NOT_RETRYABLE);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return (bool) $this->getExporter()?->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return (bool) $this->getExporter()?->forceFlush($cancellation);
    }

    private function getExporter(): ?SpanExporterInterface
    {
        if ($this->exporter === null) {
            $this->exporter = $this->exporterFactory->fromEnvironment();
        }

        return $this->exporter;
    }
}
