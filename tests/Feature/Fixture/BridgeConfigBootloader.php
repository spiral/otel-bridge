<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Config\ConfiguratorInterface;
use Spiral\Config\Patch\Set;
use Spiral\OpenTelemetry\Config\OpenTelemetryConfig;

/**
 * Sets `opentelemetry` values for the apps a test boots itself, leaving the app booted before the test
 * with the defaults; `#[Config]` would apply to both.
 */
final class BridgeConfigBootloader extends Bootloader
{
    /** @var array<non-empty-string, mixed> Values by their path in the section. */
    public static array $values = [];

    public function init(ConfiguratorInterface $configurator): void
    {
        foreach (self::$values as $path => $value) {
            $configurator->modify(OpenTelemetryConfig::CONFIG, new Set($path, $value));
        }
    }
}
