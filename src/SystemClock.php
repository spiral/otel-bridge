<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\SDK\Common\Time\ClockFactory;
use Spiral\Telemetry\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return ClockFactory::getDefault()->now();
    }
}
