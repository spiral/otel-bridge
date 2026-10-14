<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

enum AttributesPriority: int
{
    case Low = 7;
    case High = 42;
}
