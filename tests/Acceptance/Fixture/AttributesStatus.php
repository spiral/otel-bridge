<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

/**
 * A string-backed enum whose class name does not contain any of its values.
 */
enum AttributesStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';
}
