<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Keeps the names of exported spans and the flush and shutdown calls, and answers those two with
 * `$result`: a bool to return or a throwable to throw.
 */
final class RecordingSpanExporter implements SpanExporterInterface
{
    /** @var list<string> */
    public array $exported = [];

    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        public bool|\Throwable $result = true,
    ) {}

    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        foreach ($batch as $span) {
            \assert($span instanceof SpanDataInterface);
            $this->exported[] = $span->getName();
        }

        return new CompletedFuture(true);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->answer('shutdown');
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->answer('forceFlush');
    }

    private function answer(string $call): bool
    {
        $this->calls[] = $call;

        return $this->result instanceof \Throwable ? throw $this->result : $this->result;
    }
}
