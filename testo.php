<?php

declare(strict_types=1);

use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\TrapPlugin;
use Spiral\Testing\SpiralTestingPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Double\DoublePlugin;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(name: 'Unit', location: ['tests/Unit']),
        new SuiteConfig(name: 'Feature', location: ['tests/Feature']),
        new SuiteConfig(name: 'Acceptance', location: ['tests/Acceptance'], plugins: [new TrapPlugin()]),
    ],
    plugins: [
        new SpiralTestingPlugin(),
        new DoublePlugin(),
    ],
);
