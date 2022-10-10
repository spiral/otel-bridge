<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Config;

use Spiral\Core\InjectableConfig;

final class OpenTelemetryConfig extends InjectableConfig
{
    public const CONFIG = 'opentelemetry';

    protected array $config = [
        'app_name' => '',
        'dsn' => 'console' // 'console', 'memory', 'otlp+http'
    ];

    public function getName(): string
    {
        return $this->config['app_name'];
    }

    public function getDsn(): string
    {
        return $this->config['dsn'];
    }
}
