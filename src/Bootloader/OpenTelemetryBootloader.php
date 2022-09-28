<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Bootloader;

use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Config\ConfiguratorInterface;
use Spiral\OpenTelemetry\Config\OpenTelemetryConfig;

class OpenTelemetryBootloader extends Bootloader
{
    protected const BINDINGS = [];
    protected const SINGLETONS = [];

    public function __construct(
        private readonly ConfiguratorInterface $config
    ) {
    }

    public function init(): void
    {
        $this->initConfig();
    }

    private function initConfig(): void
    {
        $this->config->setDefaults(
            OpenTelemetryConfig::CONFIG,
            []
        );
    }
}
