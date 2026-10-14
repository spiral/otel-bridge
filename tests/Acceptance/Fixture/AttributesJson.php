<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

final readonly class AttributesJson implements \JsonSerializable
{
    public function __construct(
        private mixed $data,
    ) {}

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
