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
     * Nesting limit for structured attribute values, the default of the OTel AttributeValueDepthLimit.
     * Keeps a recursive walk far below Xdebug's max_nesting_level.
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
     * Convert mixed values to scalar or null.
     *
     * @param iterable<non-empty-string, mixed> $attributes
     *
     * @return iterable<non-empty-string, null|scalar|array<array-key, null|scalar>>
     */
    private static function normalizeAttributes(iterable $attributes): iterable
    {
        $normalized = [];
        /** @psalm-suppress MixedAssignment Attribute values are mixed by contract */
        foreach ($attributes as $key => $value) {
            $normalized[$key] = match (true) {
                !\is_array($value) => self::normalizeAttributeValue($value),
                \array_keys($value) === [0] => self::normalizeAttributeValue($value[0]),
                default => \array_map(self::normalizeAttributeValue(...), $value),
            };
        }

        return $normalized;
    }

    /**
     * Convert a single value to scalar or null.
     */
    private static function normalizeAttributeValue(mixed $value): null|bool|int|float|string
    {
        return match (true) {
            $value === null || \is_scalar($value) => $value,
            $value instanceof \Stringable => $value->__toString(),
            \is_array($value) || $value instanceof \JsonSerializable => self::encodeJson($value),
            \is_object($value) => $value::class,
            default => \get_debug_type($value),
        };
    }

    /**
     * Encode a structured value to the JSON string the OTel spec prescribes for an AnyValue in a non-OTLP
     * representation: lists become arrays, maps become objects, null becomes `null`, non-finite floats become
     * the strings `"NaN"`, `"Infinity"` and `"-Infinity"`, and invalid UTF-8 is replaced with U+FFFD.
     *
     * Non-ASCII characters and slashes are not escaped.
     *
     * Never fails: a value that cannot be encoded (a resource inside, nesting deeper than {@see self::MAX_DEPTH},
     * which includes a recursive array, or a throwing jsonSerialize()) becomes its type, e.g. `array` or the class name.
     */
    private static function encodeJson(array|\JsonSerializable $value): string
    {
        try {
            $json = \json_encode(
                self::prepareJson($value, self::MAX_DEPTH),
                \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            );
        } catch (\Throwable) {
            # jsonSerialize() is user code, and the attributes are set after the callback has already succeeded.
            $json = false;
        }

        return $json === false ? \get_debug_type($value) : $json;
    }

    /**
     * Resolve JsonSerializable values and replace non-finite floats, which json_encode() rejects.
     *
     * @param int $depth How many more levels of arrays and JsonSerializable objects may be entered.
     *
     * @throws \JsonException when the nesting exceeds $depth, which also stops a recursive array.
     */
    private static function prepareJson(mixed $value, int $depth): mixed
    {
        if ($depth === 0 && (\is_array($value) || $value instanceof \JsonSerializable)) {
            throw new \JsonException('Maximum nesting depth exceeded.');
        }

        return match (true) {
            $value instanceof \JsonSerializable => self::prepareJson($value->jsonSerialize(), $depth - 1),
            \is_array($value) => \array_map(static fn(mixed $item): mixed => self::prepareJson($item, $depth - 1), $value),
            \is_float($value) && \is_nan($value) => 'NaN',
            $value === \INF => 'Infinity',
            $value === -\INF => '-Infinity',
            default => $value,
        };
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
