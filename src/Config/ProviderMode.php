<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Config;

/**
 * Where the tracer provider and propagator of the app come from.
 */
enum ProviderMode: string
{
    /**
     * From `Globals` when the SDK autoloader has built them (`OTEL_PHP_AUTOLOAD_ENABLED=true`), otherwise
     * built by the bridge.
     */
    case Auto = 'auto';

    /**
     * Always built by the bridge and registered in `Globals`, whatever `Globals` holds.
     */
    case Bridge = 'bridge';

    /**
     * Taken from `Globals`, whoever put them there; built by the bridge when `Globals` has no SDK tracer
     * provider.
     */
    case Globals = 'globals';
}
