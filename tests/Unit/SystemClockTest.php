<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit;

use Spiral\OpenTelemetry\SystemClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SystemClock::class)]
final class SystemClockTest
{
    public function nowReturnsUnixTimeInNanoseconds(): void
    {
        $expected = (int) (\microtime(true) * 1e9);

        $now = (new SystemClock())->now();

        // The OTel clock is hrtime-based with a reference taken once, so it may drift a little from microtime().
        Assert::int($now)->greaterThan($expected - 1_000_000_000)->lessThan($expected + 1_000_000_000);
    }
}
