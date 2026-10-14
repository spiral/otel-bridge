<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Spiral\Queue\JobHandler;
use Spiral\Telemetry\TracerInterface;

/**
 * The `mail.send` job: delivers to `to` of the payload inside a scoped `deliver` trace.
 */
final class SpiralIntegrationMailJob extends JobHandler
{
    public function invoke(string $to, TracerInterface $tracer, SpiralIntegrationOutbox $outbox): void
    {
        $tracer->trace('deliver', static function () use ($to, $outbox): void {
            $outbox->delivered[] = $to;
        }, scoped: true);
    }
}
