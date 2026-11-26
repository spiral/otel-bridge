<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use Spiral\Telemetry\SpanInterface;

/**
 * @deprecated Use {@see \Spiral\Telemetry\CurrentSpan}, which gives the same span with this bridge, and
 *             a span with any other telemetry driver.
 */
final class CurrentSpan
{
    private function __construct() {}

    public static function get(): SpanInterface
    {
        return \Spiral\Telemetry\CurrentSpan::get();
    }
}
