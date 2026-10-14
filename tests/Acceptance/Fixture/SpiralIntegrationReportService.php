<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Spiral\Telemetry\TracerInterface;

/**
 * A service holding the TracerInterface it was constructed with, traced as `build report`.
 */
final class SpiralIntegrationReportService
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {}

    public function build(): string
    {
        return $this->tracer->trace('build report', static fn(): string => 'report', scoped: true);
    }
}
