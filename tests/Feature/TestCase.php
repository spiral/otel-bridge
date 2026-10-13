<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use Spiral\Boot\Bootloader\ConfigurationBootloader;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;

abstract class TestCase extends \Spiral\Testing\TestCase
{
    public function rootDirectory(): string
    {
        return __DIR__ . '/..';
    }

    public function defineBootloaders(): array
    {
        return [
            ConfigurationBootloader::class,
            OpenTelemetryBootloader::class,
        ];
    }
}
