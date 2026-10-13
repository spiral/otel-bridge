<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Common\Time\Clock;
use Spiral\Telemetry\ClockInterface;

final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): int
    {
        return Clock::getDefault()->now();
    }
}
