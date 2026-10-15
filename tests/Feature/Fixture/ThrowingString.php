<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

final class ThrowingString implements \Stringable
{
    public function __toString(): string
    {
        throw new \RuntimeException('Cannot convert.');
    }
}
