<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Spiral\Queue\JobHandler;
use Spiral\Telemetry\TracerInterface;

/**
 * The `mail.send` job: delivers to `to` of the payload inside a scoped `deliver` trace.
 *
 * {@see SpiralIntegrationAudit} records `mail.to` where the job runs and `mail.delivered` (true) inside
 * `deliver`.
 */
final class SpiralIntegrationMailJob extends JobHandler
{
    public function invoke(
        string $to,
        TracerInterface $tracer,
        SpiralIntegrationOutbox $outbox,
        SpiralIntegrationAudit $audit,
    ): void {
        $audit->record('mail.to', $to);
        $tracer->trace('deliver', static function () use ($to, $outbox, $audit): void {
            $outbox->delivered[] = $to;
            $audit->record('mail.delivered', true);
        }, scoped: true);
    }
}
