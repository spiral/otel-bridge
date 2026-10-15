<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use OpenTelemetry\API\Behavior\Internal\Logging;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesHttpBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesJson;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesPassMiddleware;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesPriority;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesSize;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesStatus;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\AttributesText;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\SdkLogWriter;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TracerInterface;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Data\DataCross;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * Attribute values set through Spiral's tracer, as they arrive in Trap.
 *
 * An attribute value is a primitive, an array of primitives of one type, or (since the AnyValue
 * extension) a byte array, a heterogeneous array or a map; empty strings and empty arrays must be
 * passed on, and `null` items of an array must be kept or replaced by 0, false or an empty string:
 * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/common/README.md#attribute
 *
 * The PHP SDK still validates against the older rule (primitives or homogeneous arrays only) and drops
 * anything else, so where the spec allows several shapes the tests accept any shape that loses no data,
 * see {@see self::preserves()}.
 */
#[Test]
final class AttributesTest extends TestCase
{
    private const SPAN = 'attrs';

    public static function scalars(): iterable
    {
        yield 'string' => ['text'];
        yield 'empty string' => [''];
        yield 'numeric string' => ['42'];
        yield 'unicode string' => ['héllo 世界 🙂'];
        yield 'int' => [42];
        yield 'zero' => [0];
        yield 'negative int' => [-7];
        yield 'max int' => [\PHP_INT_MAX];
        yield 'min int' => [\PHP_INT_MIN];
        yield 'float' => [1.5];
        yield 'whole float' => [2.0];
        yield 'negative float' => [-0.25];
        yield 'true' => [true];
        yield 'false' => [false];
    }

    public static function homogeneousLists(): iterable
    {
        yield 'strings' => [['a', 'b'], ['a', 'b']];
        yield 'empty strings' => [['', ''], ['', '']];
        yield 'ints' => [[1, 2, 3], [1, 2, 3]];
        yield 'floats' => [[1.5, 2.0], [1.5, 2.0]];
        yield 'bools' => [[true, false], [true, false]];
        yield 'empty' => [[], []];
        yield 'Stringables' => [[new AttributesText('a'), new AttributesText('b')], ['a', 'b']];
    }

    public static function singleItemLists(): iterable
    {
        yield 'string' => [['X'], ['X']];
        yield 'empty string' => [[''], ['']];
        yield 'int' => [[42], [42]];
        yield 'float' => [[1.5], [1.5]];
        yield 'true' => [[true], [true]];
        yield 'false' => [[false], [false]];
        yield 'numeric-string key' => [['0' => 'X'], ['X']];
        yield 'Stringable' => [[new AttributesText('a')], ['a']];
    }

    public static function preservedValues(): iterable
    {
        yield 'int and float' => [[1, 2.5]];
        yield 'max int and float' => [[\PHP_INT_MAX, 1.5]];
        yield 'string and nested list' => [['a', ['b']]];
        yield 'nested lists' => [[['a'], ['b', 'c']]];
        yield 'string and Stringable' => [['a', new AttributesText('b')]];
        yield 'map' => [['k' => 'v']];
        yield 'map of strings' => [['a' => 'x', 'b' => 'y']];
        yield 'map of bools' => [['a' => true, 'b' => false]];
        yield 'nested map' => [['user' => ['id' => 1, 'name' => 'x']]];
        yield 'int key not zero' => [[1 => 'x']];
        yield 'list from array_filter' => [[1 => 'a', 2 => 'b']];
        yield 'Stringable' => [new AttributesText('text')];
        yield 'JsonSerializable' => [new AttributesJson(['a' => 1])];
    }

    public static function droppedValues(): iterable
    {
        yield 'strings and int' => [['foo', 'bar', 42]];
        yield 'int and string' => [[1, 'x']];
        yield 'bool and string' => [[true, 'x']];
        yield 'float and string' => [[1.5, 'x']];
        yield 'Stringable and int' => [[new AttributesText('a'), 1]];
        yield 'string and null' => [['a', null]];
        yield 'null and string' => [[null, 'a']];
        yield 'int and null' => [[1, null]];
        yield 'bool and null' => [[true, null]];
        yield 'only null' => [[null]];
        yield 'nulls' => [[null, null]];
        yield 'all types' => [['a', 1, true, 1.5, null]];
        yield 'map of mixed' => [['a' => 1, 'b' => 'x']];
        yield 'map with null' => [['k' => null]];
        yield 'string enum' => [AttributesStatus::Paid];
        yield 'int enum' => [AttributesPriority::High];
        yield 'unit enum' => [AttributesSize::Small];
        yield 'list of enums' => [[AttributesStatus::Paid, AttributesStatus::Refunded]];
        yield 'DateTimeImmutable' => [new \DateTimeImmutable('2024-02-29T13:14:15+03:00')];
        yield 'DateTime' => [new \DateTime('1999-12-31T23:59:59Z')];
    }

    public static function setters(): iterable
    {
        yield 'start attributes' => [
            static fn(TracerInterface $tracer, mixed $value) => $tracer->trace(self::SPAN, static fn() => null, attributes: ['value' => $value]),
        ];
        yield 'setAttribute()' => [
            static fn(TracerInterface $tracer, mixed $value) => $tracer->trace(
                self::SPAN,
                static fn(SpanInterface $span) => $span->setAttribute('value', $value),
            ),
        ];
        yield 'setAttributes()' => [
            static fn(TracerInterface $tracer, mixed $value) => $tracer->trace(
                self::SPAN,
                static fn(SpanInterface $span) => $span->setAttributes(['value' => $value]),
            ),
        ];
    }

    public static function settableValues(): iterable
    {
        yield 'scalar' => ['plain'];
        yield 'list' => [['a', 'b']];
        yield 'map' => [['k' => 'v']];
    }

    public static function droppedSettableValues(): iterable
    {
        yield 'list of one' => [['X']];
        yield 'mixed list' => [['a', 1]];
        yield 'list with null' => [['a', null]];
    }

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), AttributesHttpBootloader::class];
    }

    #[DataProvider('scalars')]
    public function scalarValueArrivesWithItsType(mixed $value): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $value]);
    }

    #[Env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')]
    #[DataProvider('scalars')]
    public function scalarValueArrivesWithItsTypeOverProtobuf(mixed $value): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $value]);
    }

    /**
     * A homogeneous array stays an array of the same items: its length does not change its type.
     */
    #[DataProvider('homogeneousLists')]
    public function homogeneousListArrivesAsTheSameList(array $value, array $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $expected]);
    }

    #[DataProvider('singleItemLists')]
    #[Skip('Known issue: single-element lists are collapsed to a scalar, see https://github.com/spiral/otel-bridge/issues/20')]
    public function singleItemListArrivesAsListOfOne(array $value, array $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $expected]);
    }

    #[Env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')]
    #[DataProvider('homogeneousLists')]
    public function homogeneousListArrivesAsTheSameListOverProtobuf(array $value, array $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $expected]);
    }

    /**
     * Values that already arrive intact; a fix must not break them.
     */
    #[DataProvider('preservedValues')]
    public function valueArrivesWithoutDataLoss(mixed $value): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertPreserved($value, $this->received());
    }

    /**
     * @todo Question: which shape should mixed lists and associative arrays take? The spec allows AnyValue
     *       (heterogeneous arrays, maps); #20 proposes string[] and JSON. The test accepts any lossless shape.
     */
    #[DataProvider('droppedValues')]
    #[Skip('Known issue: mixed, null-containing and enum/date values are dropped or reduced to a class name, see https://github.com/spiral/otel-bridge/issues/20')]
    public function droppedValueArrivesWithoutDataLoss(mixed $value): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertPreserved($value, $this->received());
    }

    /**
     * What json_encode() cannot encode is replaced as the OTel spec prescribes for a non-OTLP AnyValue:
     * invalid UTF-8 with U+FFFD, NaN with the JSON string "NaN".
     */
    #[DataSet([new AttributesJson(["\xFF"]), '["�"]'], 'JsonSerializable with invalid UTF-8')]
    #[DataSet([['a', ["\xFF"]], ['a', '["�"]']], 'nested list with invalid UTF-8')]
    #[DataSet([['k' => ["\xFF"]], ['k' => '["�"]']], 'map with nested invalid UTF-8')]
    #[DataSet([['a', [\NAN]], ['a', '["NaN"]']], 'nested NaN')]
    public function unencodableValueArrivesAsJsonWithReplacements(mixed $value, string|array $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $expected]);
    }

    /**
     * Raw bytes go out as OTLP `bytesValue`, which Trap shows base64-encoded.
     */
    #[DataSet(["\xFF\xFE", '//4='], 'scalar')]
    #[DataSet([['ok', "\xFF"], ['ok', '/w==']], 'in a list')]
    public function invalidUtf8StringArrivesAsBytes(string|array $value, string|array $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $expected]);
    }

    public function veryLongStringArrivesIntact(): void
    {
        $value = \str_repeat('0123456789абвгд', 20_000);

        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        $this->assertReceived(['value' => $value]);
    }

    /**
     * The start attributes, setAttribute() and setAttributes() all go through the same conversion.
     */
    #[DataCross(new DataProvider('setters'), new DataProvider('settableValues'))]
    public function valueArrivesTheSameWhereverItIsSet(\Closure $trace, mixed $value): void
    {
        $trace($this->tracer(), $value);

        $this->assertPreserved($value, $this->received());
    }

    #[DataCross(new DataProvider('setters'), new DataProvider('droppedSettableValues'))]
    #[Skip('Known issue: single-element and mixed lists are collapsed or dropped, see https://github.com/spiral/otel-bridge/issues/20')]
    public function droppedValueArrivesTheSameWhereverItIsSet(\Closure $trace, mixed $value): void
    {
        $trace($this->tracer(), $value);

        $this->assertPreserved($value, $this->received());
    }

    #[DataSet(['start', 'callback', 'callback'], 'scalar by scalar')]
    #[DataSet([['x', 'y'], ['a', 'b', 'c'], ['a', 'b', 'c']], 'list by longer list')]
    #[DataSet([['x', 'y'], 'scalar', 'scalar'], 'list by scalar')]
    #[DataSet(['scalar', [1, 2], [1, 2]], 'scalar by list')]
    public function attributeSetInCallbackOverridesStartAttribute(mixed $start, mixed $callback, mixed $expected): void
    {
        $this->tracer()->trace(
            self::SPAN,
            static fn(SpanInterface $span) => $span->setAttribute('value', $callback),
            attributes: ['value' => $start, 'kept' => 'yes'],
        );

        $this->assertReceived(['value' => $expected, 'kept' => 'yes']);
    }

    #[DataSet([['x', 'y'], ['z'], ['z']], 'list by list of one')]
    #[DataSet(['scalar', ['z'], ['z']], 'scalar by list of one')]
    #[Skip('Known issue: single-element lists are collapsed to a scalar, see https://github.com/spiral/otel-bridge/issues/20')]
    public function attributeOverriddenByListOfOneArrivesAsList(mixed $start, mixed $callback, mixed $expected): void
    {
        $this->tracer()->trace(
            self::SPAN,
            static fn(SpanInterface $span) => $span->setAttribute('value', $callback),
            attributes: ['value' => $start, 'kept' => 'yes'],
        );

        $this->assertReceived(['value' => $expected, 'kept' => 'yes']);
    }

    public function nullValueLeavesOtherAttributesIntact(): void
    {
        $this->tracer()->trace(self::SPAN, static function (SpanInterface $span): void {
            $span->setAttribute('before', 'b');
            $span->setAttribute('null', null);
            $span->setAttribute('after', 'a');
        });

        $received = $this->received();
        Assert::same($received['before'] ?? null, 'b');
        Assert::same($received['after'] ?? null, 'a');
        Assert::true(\in_array($received['null'] ?? null, [null, ''], true), 'Received ' . self::export($received['null'] ?? null));
    }

    /**
     * Strings are cut to the limit in characters, each string of an array separately; other types are not affected.
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/common/README.md#attribute-limits
     */
    #[Env('OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT', '5')]
    public function valueLengthLimitTruncatesStringsAndStringItems(): void
    {
        $this->tracer()->trace(self::SPAN, static function (SpanInterface $span): void {
            $span->setAttribute('ascii', 'abcdefgh');
            $span->setAttribute('multibyte', 'приветмир');
            $span->setAttribute('short', 'abc');
            $span->setAttribute('list', ['abcdefgh', 'xy']);
            $span->setAttribute('int', 1234567);
            $span->setAttribute('bool', true);
        }, attributes: ['start' => 'abcdefgh']);

        $this->assertReceived([
            'start' => 'abcde',
            'ascii' => 'abcde',
            'multibyte' => 'приве',
            'short' => 'abc',
            'list' => ['abcde', 'xy'],
            'int' => 1234567,
            'bool' => true,
        ]);
    }

    #[Env('OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT', '5')]
    #[Skip('Known issue: single-element lists are collapsed to a scalar, see https://github.com/spiral/otel-bridge/issues/20')]
    public function valueLengthLimitKeepsListOfOneAList(): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('list', ['abcdefgh']));

        $this->assertReceived(['list' => ['abcde']]);
    }

    /**
     * Past the count limit new keys are discarded and counted as dropped, while an existing key can
     * still be overwritten.
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/common/README.md#attribute-limits
     */
    #[Env('OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT', '2')]
    public function countLimitKeepsFirstKeysAndOverrides(): void
    {
        # The SDK warns about dropped attributes; keep it off stderr, where Infection stops the run.
        $log = new SdkLogWriter();
        Logging::setLogWriter($log);

        try {
            $this->tracer()->trace(self::SPAN, static function (SpanInterface $span): void {
                $span->setAttribute('a', 'override');
                $span->setAttribute('c', 'over the limit');
            }, attributes: ['a' => 'start', 'b' => ['x', 'y']]);
        } finally {
            Logging::reset();
        }

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->attributes, ['a' => 'override', 'b' => ['x', 'y']]);
        Assert::same($span->droppedAttributesCount, 1);
        Assert::same(\count($log->problems()), 1);
    }

    /**
     * `spiral/http` builds `http.middleware` as a list, one item per middleware.
     */
    #[Env('ATTRIBUTES_HTTP_MIDDLEWARE', '2')]
    public function middlewareListArrivesAsList(): void
    {
        $this->fakeHttp()->get('/ping')->assertOk();

        $span = $this->traces()->await(2)->named('HTTP Pipeline');
        Assert::same($span->attributes['http.middleware'] ?? null, [
            AttributesPassMiddleware::class,
            AttributesPassMiddleware::class,
        ]);
    }

    #[Env('ATTRIBUTES_HTTP_MIDDLEWARE', '1')]
    #[Skip('Known issue: single-element lists are collapsed to a scalar, see https://github.com/spiral/otel-bridge/issues/20')]
    public function singleMiddlewareArrivesAsListOfOne(): void
    {
        $this->fakeHttp()->get('/ping')->assertOk();

        $span = $this->traces()->await(2)->named('HTTP Pipeline');
        Assert::same($span->attributes['http.middleware'] ?? null, [AttributesPassMiddleware::class]);
    }

    /**
     * `spiral/http` sends the request headers as a `[name => value]` array.
     */
    public function requestHeadersArriveWithoutDataLoss(): void
    {
        $this->fakeHttp()->get('/ping', headers: ['x-one' => 'a', 'x-many' => ['b', 'c']])->assertOk();

        $span = $this->traces()->await(1)->named('GET /ping');
        $headers = $span->attributes['http.headers'] ?? null;
        $headers = \is_string($headers) ? \json_decode($headers, true) : $headers;
        Assert::true(\is_array($headers), 'Received ' . self::export($span->attributes['http.headers'] ?? null));
        Assert::same($headers['x-one'] ?? null, 'a');
        Assert::same($headers['x-many'] ?? null, 'b,c');
    }

    /**
     * Whether `$received` carries everything of `$sent`, in any shape the spec allows: the value itself,
     * an item as its string form (bools as `true`/`false`), `null` items as null or an empty string, an
     * array or map as a list or map of preserved items or as its JSON. A list must keep its length and
     * order, a map its keys; an array of one item must not become a scalar.
     */
    private static function preserves(mixed $sent, mixed $received): bool
    {
        return match (true) {
            $sent === $received => true,
            $sent === null => $received === null || $received === '',
            \is_bool($sent) => $received === ($sent ? 'true' : 'false'),
            \is_int($sent), \is_float($sent) => (\is_int($received) || \is_float($received) || \is_numeric($received))
                && (float) $received === (float) $sent,
            \is_string($sent) => false,
            $sent instanceof \BackedEnum => self::preserves($sent->value, $received)
                || (\is_string($received) && \str_contains($received, (string) $sent->value)),
            $sent instanceof \UnitEnum => \is_string($received) && \str_contains($received, $sent->name),
            $sent instanceof \DateTimeInterface => \is_string($received)
                && \strtotime($received) === $sent->getTimestamp(),
            $sent instanceof \Stringable => self::preserves((string) $sent, $received),
            $sent instanceof \JsonSerializable => self::preserves($sent->jsonSerialize(), $received),
            !\is_array($sent) => false,
            \is_string($received) => \is_array($decoded = \json_decode($received, true))
                && self::preservesArray($sent, $decoded),
            \is_array($received) => self::preservesArray($sent, $received),
            default => false,
        };
    }

    private static function preservesArray(array $sent, array $received): bool
    {
        if (\count($sent) !== \count($received)) {
            return false;
        }

        if (\array_is_list($sent)) {
            $received = \array_is_list($received) ? $received : [];
        } elseif (\array_keys($sent) !== \array_keys($received)) {
            # A list with a hole, like after array_filter(), may arrive as a plain list; a map with string keys may not.
            $intKeys = \array_filter(\array_keys($sent), \is_int(...)) === \array_keys($sent);
            if (!$intKeys || !\array_is_list($received)) {
                return false;
            }
            $sent = \array_values($sent);
        }

        foreach ($sent as $key => $item) {
            if (!\array_key_exists($key, $received) || !self::preserves($item, $received[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function export(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR)
            . ' (' . \get_debug_type($value) . ')';
    }

    /**
     * Attributes of the only span of the test.
     */
    private function received(): array
    {
        return $this->traces()->await(1)->named(self::SPAN)->attributes;
    }

    private function assertReceived(array $expected): void
    {
        $received = $this->received();

        Assert::same($received, $expected, 'Received ' . self::export($received));
    }

    private function assertPreserved(mixed $value, array $received): void
    {
        Assert::true(\array_key_exists('value', $received), 'The attribute was dropped.');
        Assert::true(
            self::preserves($value, $received['value']),
            \sprintf('Sent %s, received %s', self::export($value), self::export($received['value'])),
        );
    }
}
