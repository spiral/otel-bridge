<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

final readonly class JsonValue implements \JsonSerializable
{
    public function __construct(
        private mixed $data,
    ) {}

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
