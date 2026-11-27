<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Spiral\Telemetry\CurrentSpan;

/**
 * A service with no span passed to it: it records on the span it runs in.
 */
final class SpiralIntegrationAudit
{
    public function record(string $key, mixed $value): void
    {
        CurrentSpan::get()->setAttribute($key, $value);
    }
}
