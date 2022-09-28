<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Config;

use Spiral\Core\InjectableConfig;

final class OpenTelemetryConfig extends InjectableConfig
{
    public const CONFIG = 'opentelemetry';
    protected array $config = [

    ];
}
