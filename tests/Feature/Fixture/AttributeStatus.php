<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

enum AttributeStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';
}
