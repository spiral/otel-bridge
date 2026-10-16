<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

/**
 * A span as Trap received it over OTLP: ids are lowercase hex, times are nanoseconds, and kind and
 * status code keep their OTLP numbers, which differ from the PHP API constants.
 *
 * `flags` is the OTLP `SpanFlags` field: the low byte holds the W3C trace flags, bit 0x100 says whether
 * the parent is known to be remote or not and bit 0x200 that it is remote
 * (https://github.com/open-telemetry/opentelemetry-proto/blob/v1.5.0/opentelemetry/proto/trace/v1/trace.proto#L122).
 */
final readonly class ReceivedSpan
{
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;
    public const KIND_PRODUCER = 4;
    public const KIND_CONSUMER = 5;
    public const STATUS_UNSET = 0;
    public const STATUS_OK = 1;
    public const STATUS_ERROR = 2;

    /**
     * @param array<string, mixed> $resourceAttributes
     * @param array<string, mixed> $attributes
     * @param list<array{name: string, timeUnixNano: int, attributes: array<string, mixed>}> $events
     */
    public function __construct(
        public array $resourceAttributes,
        public string $scopeName,
        public string $scopeVersion,
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public int $kind,
        public int $startTimeUnixNano,
        public int $endTimeUnixNano,
        public array $attributes,
        public array $events,
        public int $statusCode,
        public string $statusMessage,
        public int $flags,
        public string $traceState,
        public int $droppedAttributesCount,
        public int $droppedEventsCount,
        public int $droppedLinksCount,
    ) {}

    /**
     * Builds a span from one element of `resourceSpans[].scopeSpans[].spans[]` of a Trap dump line.
     */
    public static function fromDump(array $resourceSpans, array $scopeSpans, array $span): self
    {
        return new self(
            resourceAttributes: (array) ($resourceSpans['resource']['attributes'] ?? []),
            scopeName: (string) ($scopeSpans['scope']['name'] ?? ''),
            scopeVersion: (string) ($scopeSpans['scope']['version'] ?? ''),
            traceId: (string) $span['traceId'],
            spanId: (string) $span['spanId'],
            parentSpanId: isset($span['parentSpanId']) ? (string) $span['parentSpanId'] : null,
            name: (string) $span['name'],
            kind: (int) $span['kind'],
            startTimeUnixNano: (int) $span['startTimeUnixNano'],
            endTimeUnixNano: (int) $span['endTimeUnixNano'],
            attributes: (array) ($span['attributes'] ?? []),
            events: \array_map(
                static fn(array $event): array => [
                    'name' => (string) $event['name'],
                    'timeUnixNano' => (int) $event['timeUnixNano'],
                    'attributes' => (array) ($event['attributes'] ?? []),
                ],
                \array_values((array) ($span['events'] ?? [])),
            ),
            statusCode: (int) ($span['status']['code'] ?? self::STATUS_UNSET),
            statusMessage: (string) ($span['status']['message'] ?? ''),
            flags: (int) ($span['flags'] ?? 0),
            traceState: (string) ($span['traceState'] ?? ''),
            droppedAttributesCount: (int) ($span['droppedAttributesCount'] ?? 0),
            droppedEventsCount: (int) ($span['droppedEventsCount'] ?? 0),
            droppedLinksCount: (int) ($span['droppedLinksCount'] ?? 0),
        );
    }

    public function serviceName(): ?string
    {
        $name = $this->resourceAttributes['service.name'] ?? null;

        return \is_string($name) ? $name : null;
    }

    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    public function __toString(): string
    {
        return \sprintf(
            '%s span=%s parent=%s trace=%s kind=%d status=%d flags=0x%x',
            $this->name,
            $this->spanId,
            $this->parentSpanId ?? '-',
            $this->traceId,
            $this->kind,
            $this->statusCode,
            $this->flags,
        );
    }
}
