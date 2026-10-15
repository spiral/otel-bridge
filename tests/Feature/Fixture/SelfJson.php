<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

final class SelfJson implements \JsonSerializable
{
    public function jsonSerialize(): self
    {
        return $this;
    }
}
