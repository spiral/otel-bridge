<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

use Spiral\Telemetry\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(
        public int $now = 1_000_000_000_000,
    ) {}

    public function advance(int|float $seconds): void
    {
        $this->now += (int) ($seconds * 1_000_000_000);
    }

    #[\Override]
    public function now(): int
    {
        return $this->now;
    }
}
