<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Span;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use Spiral\Telemetry\Span\Status;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;

/**
 * An OTel span not started by the bridge, as {@see OtelCurrentSpanProvider} gives it: a span of
 * `#[WithSpan]`, of a hook, or one activated by hand. Values are converted as for a Spiral trace, but
 * written to the OTel span at once, as it has no end of a trace to wait for. So, unlike a trace's span:
 * the getters give converted values; setAttributes() adds to the attributes rather than replacing them,
 * which would drop those of the hook; and the SDK's rules apply to the status at once: Ok is final, and
 * a code that converts to Unset is ignored.
 *
 * @internal
 */
final class OtelSpanAdapter implements SpanInterface
{
    public function __construct(
        private readonly ReadWriteSpanInterface $span,
    ) {}

    #[\Override]
    public function getName(): string
    {
        /** @var non-empty-string The SDK takes non-empty names only */
        return $this->span->getName();
    }

    #[\Override]
    public function updateName(string $name): self
    {
        $this->span->updateName($name);

        return $this;
    }

    #[\Override]
    public function setStatus(string|int $code, ?string $description = null): self
    {
        $kind = $this->span->getKind() === SpanKind::KIND_SERVER ? TraceKind::SERVER : null;
        $this->span->setStatus(SpanConverter::statusCode($code, $kind), $description);

        return $this;
    }

    #[\Override]
    public function getStatus(): ?Status
    {
        $status = $this->span->toSpanData()->getStatus();
        /** @var StatusCode::STATUS_* $code */
        $code = $status->getCode();
        $description = $status->getDescription();

        return $code === StatusCode::STATUS_UNSET
            ? null
            : new Status($code, $description === '' ? null : $description);
    }

    #[\Override]
    public function getAttributes(): array
    {
        /** @var array<non-empty-string, mixed> The SDK takes non-empty keys only */
        return $this->span->toSpanData()->getAttributes()->toArray();
    }

    #[\Override]
    public function setAttributes(array $attributes): self
    {
        $this->span->setAttributes(SpanConverter::attributes($attributes));

        return $this;
    }

    #[\Override]
    public function setAttribute(string $name, mixed $value): self
    {
        return $this->setAttributes([$name => $value]);
    }

    #[\Override]
    public function hasAttribute(string $name): bool
    {
        # The SDK removes an attribute set to null, so a null value means there is none.
        return $this->span->getAttribute($name) !== null;
    }

    #[\Override]
    public function getAttribute(string $name): mixed
    {
        return $this->span->getAttribute($name);
    }
}
