<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

final readonly class AttributeText implements \Stringable
{
    public function __construct(
        private string $text,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
