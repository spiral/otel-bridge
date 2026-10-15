<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

final class StringableJson implements \Stringable, \JsonSerializable
{
    public function __toString(): string
    {
        return 'text';
    }

    public function jsonSerialize(): array
    {
        return ['json' => true];
    }
}
