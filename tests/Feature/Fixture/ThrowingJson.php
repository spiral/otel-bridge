<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

final class ThrowingJson implements \JsonSerializable
{
    public function jsonSerialize(): never
    {
        throw new \RuntimeException('Cannot serialize.');
    }
}
