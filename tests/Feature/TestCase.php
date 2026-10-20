<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use Spiral\Boot\Bootloader\ConfigurationBootloader;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Testo\Lifecycle\AfterTest;

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

    /**
     * Terminates the app as the kernel does, so the context it registers for `Globals` does not outlive
     * the test. spiral/testing leaves the kernel to the garbage collector, which clears the bindings
     * before the kernel destructor runs.
     */
    #[AfterTest]
    public function terminate(): void
    {
        $this->getContainer()->get(FinalizerInterface::class)->finalize(true);
    }
}
