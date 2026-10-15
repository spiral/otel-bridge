<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\ScopeInterface;
use Spiral\OpenTelemetry\Propagation\TracestateGetter;
use Spiral\Telemetry\AbstractTracer;
use Spiral\Telemetry\Span;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;

final class Tracer extends AbstractTracer
{
    /**
     * Depth limit for arrays and maps in an attribute value, the OTel AttributeValueDepthLimit default.
     * Deeper ones become `null`, which also cuts a recursive array.
     */
    private const MAX_DEPTH = 64;

    private ?\OpenTelemetry\API\Trace\SpanInterface $lastSpan = null;
    private readonly TracestateGetter $getter;

    public function __construct(
        #[Proxy] ScopeInterface $scope,
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private array $context = [],
    ) {
        parent::__construct($scope);
        $this->getter = new TracestateGetter();
    }

    /**
     * @param non-empty-string $name
     *
     * @throws \Throwable
     *
     * @psalm-suppress MixedAssignment The callback result is returned as is
     */
    #[\Override]
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = false,
        ?TraceKind $traceKind = null,
        ?int $startTime = null,
    ): mixed {
        $traceSpan = $this->getTraceSpan($name, $traceKind, $startTime);
        $internalSpan = $this->createInternalSpan($name, $attributes);

        $scope = null;
        if ($scoped) {
            $scope = $traceSpan->activate();
        }

        try {
            $result = $this->runScope($internalSpan, $callback);

            if (($status = $internalSpan->getStatus()) !== null) {
                $traceSpan->setStatus(self::normalizeStatusCode($status->code), $status->description);
            }

            $traceSpan->updateName($internalSpan->getName());
            $traceSpan->setAttributes(self::normalizeAttributes($internalSpan->getAttributes()));

            return $result;
        } catch (\Throwable $e) {
            $traceSpan->recordException($e);
            throw $e;
        } finally {
            $traceSpan->end();
            $scope?->detach();
        }
    }

    #[\Override]
    public function getContext(): array
    {
        if ($this->lastSpan !== null) {
            $ctx = $this->lastSpan->storeInContext(Context::getCurrent());
            $carrier = [];
            $this->propagator->inject($carrier, null, $ctx);

            /** @var array<non-empty-string, string> $carrier The default setter fills the array by reference */
            return $carrier;
        }

        return $this->context;
    }

    /**
     * @return SpanKind::KIND_*
     */
    public function convertSpanKind(?TraceKind $traceKind): int
    {
        return match ($traceKind) {
            TraceKind::CLIENT => SpanKind::KIND_CLIENT,
            TraceKind::SERVER => SpanKind::KIND_SERVER,
            TraceKind::PRODUCER => SpanKind::KIND_PRODUCER,
            TraceKind::CONSUMER => SpanKind::KIND_CONSUMER,
            default => SpanKind::KIND_INTERNAL,
        };
    }

    /**
     * @return StatusCode::STATUS_*
     */
    private static function normalizeStatusCode(int|string $code): string
    {
        if (\is_string($code)) {
            return match (\strtolower($code)) {
                'error' => StatusCode::STATUS_ERROR,
                'unset' => StatusCode::STATUS_UNSET,
                default => StatusCode::STATUS_OK,
            };
        }

        return StatusCode::STATUS_OK;
    }

    /**
     * Convert values to what the SDK accepts as an attribute: a primitive, or a homogeneous array of
     * primitives. Any other array becomes its JSON. `null` is passed on, and the SDK removes the attribute.
     *
     * @param iterable<non-empty-string, mixed> $attributes
     *
     * @return iterable<non-empty-string, null|scalar|list<scalar>>
     */
    private static function normalizeAttributes(iterable $attributes): iterable
    {
        $normalized = [];
        /** @psalm-suppress MixedAssignment Attribute values are mixed by contract */
        foreach ($attributes as $key => $value) {
            $normalized[$key] = \is_array($value)
                ? self::normalizeList($value) ?? self::encodeJson($value)
                : self::normalizeAttributeValue($value);
        }

        return $normalized;
    }

    /**
     * The list as a homogeneous array of primitives, or null when it is not one: a map, or a list with an
     * array or a JsonSerializable item, or with items of different types.
     *
     * The SDK drops a list with `null` in it, so `null` items become the empty value of the list's type,
     * as the spec allows where `null` cannot be exported. This keeps the list a list, and its indexes in
     * sync with a parallel list. A homogeneous array must not mix ints and floats, so with any float in
     * it every number becomes a float.
     *
     * @return list<scalar>|null
     */
    private static function normalizeList(array $value): ?array
    {
        if (!\array_is_list($value) || \array_filter($value, self::isStructured(...)) !== []) {
            return null;
        }

        $items = \array_map(self::normalizeAttributeValue(...), $value);
        $type = null;
        $float = false;
        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            $float = $float || \is_float($item);
            $itemType = \is_int($item) || \is_float($item) ? 'number' : \gettype($item);
            if ($type !== null && $itemType !== $type) {
                return null;
            }
            $type = $itemType;
        }

        $empty = match ($type) {
            'number' => 0,
            'boolean' => false,
            default => '',
        };

        return \array_map(
            static fn(null|bool|int|float|string $item): bool|int|float|string => $float ? (float) $item : $item ?? $empty,
            $items,
        );
    }

    /**
     * Whether the value is an AnyValue array or map rather than a primitive: an array, or a JsonSerializable
     * object that has no primitive form of its own (an enum, a date or a Stringable has one).
     */
    private static function isStructured(mixed $value): bool
    {
        return \is_array($value) || ($value instanceof \JsonSerializable
            && !$value instanceof \UnitEnum
            && !$value instanceof \DateTimeInterface
            && !$value instanceof \Stringable);
    }

    /**
     * Convert a single value to scalar or null.
     */
    private static function normalizeAttributeValue(mixed $value): null|bool|int|float|string
    {
        return match (true) {
            $value === null || \is_scalar($value) => $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(
                $value->format('u') === '000000' ? \DATE_RFC3339 : 'Y-m-d\TH:i:s.uP',
            ),
            $value instanceof \Stringable => self::callUserCode(static fn(): string => $value->__toString(), $value),
            $value instanceof \JsonSerializable => self::encodeJson($value),
            \is_object($value) => $value::class,
            default => \get_debug_type($value),
        };
    }

    /**
     * Encode a structured value to the JSON string the OTel spec prescribes for an AnyValue in a non-OTLP
     * representation, see {@see self::prepareJson()}. Non-ASCII characters and slashes are not escaped.
     * A JsonSerializable that stands for a primitive gives that primitive, not its JSON.
     */
    private static function encodeJson(array|\JsonSerializable $value): null|bool|int|float|string
    {
        $prepared = self::prepareJson($value, 1);
        if (!\is_array($prepared)) {
            return $prepared;
        }

        $json = \json_encode($prepared, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $json === false ? \get_debug_type($value) : $json;
    }

    /**
     * Convert a value to what json_encode() turns into the spec's JSON for an AnyValue: lists stay arrays,
     * maps become objects, JsonSerializable is replaced by what it serializes to, other objects by their
     * attribute value (never their public properties), non-finite floats by "NaN", "Infinity" and
     * "-Infinity". Invalid UTF-8 is left to json_encode().
     *
     * Only arrays and maps count as depth levels, starting from 1 for the attribute value. An array or map
     * deeper than {@see self::MAX_DEPTH} becomes `null`, as does a JsonSerializable that serializes back
     * to itself; this also cuts a recursive array.
     *
     * @param positive-int $depth The depth of $value.
     * @param list<\JsonSerializable> $serializing The JsonSerializable objects $value comes from.
     */
    private static function prepareJson(mixed $value, int $depth, array $serializing = []): null|bool|int|float|string|array
    {
        if (\is_array($value)) {
            return $depth > self::MAX_DEPTH ? null : \array_map(
                static fn(mixed $item): null|bool|int|float|string|array => self::prepareJson($item, $depth + 1, $serializing),
                $value,
            );
        }

        if (!$value instanceof \JsonSerializable || !self::isStructured($value)) {
            return match (true) {
                \is_float($value) && \is_nan($value) => 'NaN',
                $value === \INF => 'Infinity',
                $value === -\INF => '-Infinity',
                default => self::normalizeAttributeValue($value),
            };
        }

        return \in_array($value, $serializing, true) ? null : self::callUserCode(
            static fn(): null|bool|int|float|string|array => self::prepareJson($value->jsonSerialize(), $depth, [...$serializing, $value]),
            $value,
        );
    }

    /**
     * Run __toString() or jsonSerialize() of a value, which becomes its type when they throw: attributes are
     * set after the traced callback has succeeded, and must not make trace() fail.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T|string
     */
    private static function callUserCode(\Closure $call, object $value): mixed
    {
        try {
            return $call();
        } catch (\Throwable) {
            return \get_debug_type($value);
        }
    }

    /**
     * @param non-empty-string $name
     */
    private function createInternalSpan(string $name, array $attributes): SpanInterface
    {
        return new Span($name, $attributes);
    }

    /**
     * @param non-empty-string $name
     */
    private function getTraceSpan(
        string $name,
        ?TraceKind $traceKind,
        ?int $startTime,
    ): \OpenTelemetry\API\Trace\SpanInterface {
        $spanBuilder = $this->tracer
            ->spanBuilder($name)
            ->setSpanKind($this->convertSpanKind($traceKind));

        if ($startTime !== null) {
            $spanBuilder->setStartTimestamp($startTime);
        }

        if ($this->context !== []) {
            $spanBuilder->setParent(
                $this->propagator->extract($this->context, $this->getter),
            );
        }

        return $this->lastSpan = $spanBuilder->startSpan();
    }
}
