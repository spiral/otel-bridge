<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

/**
 * What {@see SpiralIntegrationMailJob} delivered.
 */
final class SpiralIntegrationOutbox
{
    /** @var list<string> */
    public array $delivered = [];
}
