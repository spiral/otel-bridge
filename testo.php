<?php

declare(strict_types=1);

use Spiral\Testing\SpiralTestingPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Double\DoublePlugin;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'OpenTelemetry Bridge',
            location: ['tests/src'],
        ),
    ],
    plugins: [
        new SpiralTestingPlugin(),
        new DoublePlugin(),
    ],
);
