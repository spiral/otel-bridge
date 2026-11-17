<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Span;

use Spiral\Telemetry\Span\Status;
use Spiral\Telemetry\SpanInterface;

/**
 * The span {@see \Spiral\OpenTelemetry\CurrentSpan} gives outside a trace: it keeps nothing, so code
 * that may run with or without a trace sets attributes unconditionally.
 */
final class NoopSpan implements SpanInterface
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    #[\Override]
    public function getName(): string
    {
        return 'noop';
    }

    #[\Override]
    public function updateName(string $name): self
    {
        return $this;
    }

    #[\Override]
    public function setStatus(string|int $code, ?string $description = null): self
    {
        return $this;
    }

    #[\Override]
    public function getStatus(): ?Status
    {
        return null;
    }

    #[\Override]
    public function getAttributes(): array
    {
        return [];
    }

    #[\Override]
    public function setAttributes(array $attributes): self
    {
        return $this;
    }

    #[\Override]
    public function setAttribute(string $name, mixed $value): self
    {
        return $this;
    }

    #[\Override]
    public function hasAttribute(string $name): bool
    {
        return false;
    }

    #[\Override]
    public function getAttribute(string $name): mixed
    {
        return null;
    }
}
