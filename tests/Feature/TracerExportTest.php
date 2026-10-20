<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\AttributePriority;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\AttributeSize;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\AttributeStatus;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\AttributeText;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\JsonValue;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\SelfJson;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\StringableJson;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\ThrowingJson;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\ThrowingString;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs the tracer against the real OpenTelemetry SDK and checks the exported spans.
 */
#[Test]
#[Covers(Tracer::class)]
final class TracerExportTest
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const PARENT_SPAN_ID = 'b7ad6b7169203331';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::PARENT_SPAN_ID . '-01';

    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createProvider(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    }

    #[DataSet(['error', StatusCode::STATUS_ERROR], 'error')]
    #[DataSet(['ERROR', StatusCode::STATUS_ERROR], 'case-insensitive')]
    #[DataSet([" error\n", StatusCode::STATUS_ERROR], 'padded error')]
    #[DataSet(['unset', StatusCode::STATUS_UNSET], 'unset')]
    #[DataSet(['ok', StatusCode::STATUS_OK], 'ok')]
    #[DataSet([' OK ', StatusCode::STATUS_OK], 'padded OK')]
    #[DataSet(['anything', StatusCode::STATUS_UNSET], 'unknown string')]
    #[DataSet(['', StatusCode::STATUS_UNSET], 'empty string')]
    #[DataSet(['500', StatusCode::STATUS_UNSET], 'numeric string')]
    public function statusNameIsMapped(string $code, string $expected): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setStatus($code, 'reason'));

        Assert::same($span->getStatus()->getCode(), $expected);
    }

    public static function integerStatusCodes(): iterable
    {
        $kinds = [
            'internal' => null,
            'server' => TraceKind::SERVER,
            'client' => TraceKind::CLIENT,
            'producer' => TraceKind::PRODUCER,
            'consumer' => TraceKind::CONSUMER,
        ];
        $codes = [
            0 => StatusCode::STATUS_UNSET,
            1 => StatusCode::STATUS_OK,
            2 => StatusCode::STATUS_ERROR,
            100 => StatusCode::STATUS_UNSET,
            200 => StatusCode::STATUS_UNSET,
            399 => StatusCode::STATUS_UNSET,
            500 => StatusCode::STATUS_ERROR,
            599 => StatusCode::STATUS_ERROR,
            -1 => StatusCode::STATUS_ERROR,
            3 => StatusCode::STATUS_ERROR,
            99 => StatusCode::STATUS_ERROR,
            600 => StatusCode::STATUS_ERROR,
            \PHP_INT_MAX => StatusCode::STATUS_ERROR,
        ];

        foreach ($kinds as $name => $kind) {
            foreach ($codes as $code => $expected) {
                yield "{$code} {$name}" => [$code, $kind, $expected];
            }
            foreach ([400, 404, 499] as $code) {
                yield "{$code} {$name}" => [$code, $kind, $kind === TraceKind::SERVER ? StatusCode::STATUS_UNSET : StatusCode::STATUS_ERROR];
            }
        }
    }

    #[DataProvider('integerStatusCodes')]
    public function integerStatusCodeIsMappedByKind(int $code, ?TraceKind $kind, string $expected): void
    {
        $this->createTracer()->trace('test', static fn(SpanInterface $span) => $span->setStatus($code), traceKind: $kind);

        Assert::same($this->exporter->getSpans()[0]->getStatus()->getCode(), $expected);
    }

    public function errorStatusKeepsDescription(): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setStatus('error', 'Broken pipe'));

        Assert::same($span->getStatus()->getDescription(), 'Broken pipe');
    }

    public function statusStaysUnsetWhenNotSet(): void
    {
        $span = $this->traceOne(static fn() => null);

        Assert::same($span->getStatus()->getCode(), StatusCode::STATUS_UNSET);
    }

    public function attributeValuesAreNormalized(): void
    {
        $span = $this->traceOne(static function (SpanInterface $span): void {
            $span->setAttribute('object', new \stdClass());
            $span->setAttribute('stringable', new class implements \Stringable {
                public function __toString(): string
                {
                    return 'text';
                }
            });
            $span->setAttribute('json', new class implements \JsonSerializable {
                public function jsonSerialize(): array
                {
                    return ['a' => 1];
                }
            });
        });

        $attributes = $span->getAttributes()->toArray();
        Assert::same($attributes['object'], \stdClass::class);
        Assert::same($attributes['stringable'], 'text');
        Assert::same($attributes['json'], '{"a":1}');
    }

    public static function structuredValues(): iterable
    {
        yield 'invalid UTF-8' => [['a', ["\xFF"]], '["a",["�"]]'];
        yield 'non-finite floats' => [['a', [\NAN, \INF, -\INF, 1.5]], '["a",["NaN","Infinity","-Infinity",1.5]]'];
        yield 'map with null' => [new JsonValue(['k' => ["\xFF"], 'n' => null]), '{"k":["�"],"n":null}'];
        yield 'nested JsonSerializable' => [new JsonValue([new JsonValue(\NAN)]), '["NaN"]'];
        yield 'unicode and slashes' => [['a', ['Привет', 'a/b']], '["a",["Привет","a/b"]]'];
        yield 'resource inside' => [['a', [\fopen('php://memory', 'rb')]], '["a",["resource (stream)"]]'];
    }

    /**
     * The attribute value is depth 1, each array or map one level more; past 64 an array or map becomes
     * `null` and nothing else changes.
     * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/common/README.md#attribute-limits
     */
    public static function deepValues(): iterable
    {
        $recursive = [1];
        $recursive[] = &$recursive;

        yield 'deepest allowed nesting' => [['a', self::nest(62)], '["a",' . \str_repeat('[', 63) . \str_repeat(']', 63) . ']'];
        yield 'too deep' => [['a', self::nest(63)], '["a",' . \str_repeat('[', 63) . 'null' . \str_repeat(']', 63) . ']'];
        yield 'recursive array' => [['a', $recursive], '["a",' . \str_repeat('[1,', 63) . 'null' . \str_repeat(']', 63) . ']'];
        yield 'JsonSerializable is not a level' => [new JsonValue(self::nest(63)), \str_repeat('[', 64) . \str_repeat(']', 64)];
        yield 'too deep in JsonSerializable' => [new JsonValue(self::nest(64)), \str_repeat('[', 64) . 'null' . \str_repeat(']', 64)];
        yield 'JsonSerializable serializing to itself' => [['a', new SelfJson()], '["a",null]'];
    }

    public static function serializedValues(): iterable
    {
        yield 'list item' => [['a', new JsonValue(['x' => 1])], '["a",{"x":1}]'];
        yield 'with int' => [[1, new JsonValue(['x' => 1])], '[1,{"x":1}]'];
        yield 'only item' => [[new JsonValue(['x' => 1])], '[{"x":1}]'];
        yield 'primitive in list' => [[new JsonValue('x')], '["x"]'];
        yield 'primitive string' => [new JsonValue('x'), 'x'];
        yield 'primitive int' => [new JsonValue(42), 42];
        yield 'unit enum' => [new JsonValue(AttributeSize::Small), 'Small'];
        yield 'objects inside' => [
            new JsonValue([
                's' => AttributeSize::Small,
                'p' => AttributeStatus::Paid,
                'd' => new \DateTimeImmutable('2024-02-29T13:14:15+03:00'),
                't' => new AttributeText('t'),
                'o' => new \stdClass(),
            ]),
            '{"s":"Small","p":"paid","d":"2024-02-29T13:14:15+03:00","t":"t","o":"stdClass"}',
        ];
        yield 'Stringable and JsonSerializable' => [new StringableJson(), 'text'];
        yield 'Stringable and JsonSerializable in list' => [['a', new StringableJson()], ['a', 'text']];
        yield 'Stringable and JsonSerializable in mixed list' => [[1, new StringableJson()], '[1,"text"]'];
        yield 'Stringable and JsonSerializable inside' => [new JsonValue([new StringableJson()]), '["text"]'];
    }

    /**
     * __toString() and jsonSerialize() run after the callback has succeeded, so when they throw the value
     * becomes its type and trace() still returns.
     */
    public static function throwingValues(): iterable
    {
        yield '__toString()' => [new ThrowingString(), ThrowingString::class];
        yield '__toString() in list' => [['a', new ThrowingString()], ['a', ThrowingString::class]];
        yield '__toString() in map' => [['k' => new ThrowingString()], \json_encode(['k' => ThrowingString::class])];
        yield 'jsonSerialize()' => [new ThrowingJson(), ThrowingJson::class];
        yield 'jsonSerialize() in list' => [['a', new ThrowingJson()], \json_encode(['a', ThrowingJson::class])];
        yield 'jsonSerialize() inside' => [new JsonValue([new ThrowingJson()]), \json_encode([ThrowingJson::class])];
    }

    #[DataProvider('structuredValues')]
    #[DataProvider('deepValues')]
    public function structuredAttributeIsEncodedAsJson(mixed $value, string $expected): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        Assert::same($span->getAttributes()->toArray(), ['value' => $expected]);
        Assert::same($span->getAttributes()->getDroppedAttributesCount(), 0);
        Assert::count($span->getEvents(), 0);
    }

    /**
     * A JsonSerializable stands for what it serializes to, unless it is Stringable; as a list item it makes
     * the list structured, so the list is sent as JSON.
     */
    #[DataProvider('serializedValues')]
    public function jsonSerializableIsExportedAsWhatItSerializesTo(mixed $value, mixed $expected): void
    {
        $this->assertExported($value, $expected);
    }

    #[DataProvider('throwingValues')]
    public function throwingConversionLeavesTypeInPlaceOfValue(mixed $value, mixed $expected): void
    {
        $tracer = $this->createTracer();

        $result = $tracer->trace('test', static function (SpanInterface $span) use ($value): string {
            $span->setAttribute('value', $value);
            return 'done';
        });

        Assert::same($result, 'done');
        Assert::same($this->exporter->getSpans()[0]->getAttributes()->toArray(), ['value' => $expected]);
    }

    #[DataSet([[], []], 'empty')]
    #[DataSet([['X'], ['X']], 'one string')]
    #[DataSet([[42], [42]], 'one int')]
    #[DataSet([[''], ['']], 'one empty string')]
    #[DataSet([['a', 'b'], ['a', 'b']], 'strings')]
    #[DataSet([[true, false], [true, false]], 'bools')]
    #[DataSet([[1, 2.5], [1.0, 2.5]], 'ints and floats')]
    #[DataSet([['0' => 'X'], ['X']], 'numeric-string key')]
    #[DataSet([[new AttributeText('a'), 'b'], ['a', 'b']], 'Stringable and string')]
    #[DataSet([[AttributeStatus::Paid, AttributeStatus::Refunded], ['paid', 'refunded']], 'string enums')]
    #[DataSet([[AttributeSize::Small, 'x'], ['Small', 'x']], 'unit enum and string')]
    public function listOfOneTypeIsExportedAsList(array $value, array $expected): void
    {
        $this->assertExported($value, $expected);
    }

    /**
     * `null` cannot be an item of an exported list, so it is replaced by the empty value of the list's type.
     */
    #[DataSet([['a', null], ['a', '']], 'string and null')]
    #[DataSet([[null, 'a'], ['', 'a']], 'null and string')]
    #[DataSet([[1, null, 2.5], [1.0, 0.0, 2.5]], 'numbers and null')]
    #[DataSet([[1, null], [1, 0]], 'ints and null')]
    #[DataSet([[true, null], [true, false]], 'bool and null')]
    #[DataSet([[null], ['']], 'only null')]
    #[DataSet([[null, null], ['', '']], 'nulls')]
    public function nullItemOfListIsExportedAsEmptyValueOfItsType(array $value, array $expected): void
    {
        $this->assertExported($value, $expected);
    }

    #[DataSet([['foo', 'baf', 42], '["foo","baf",42]'], 'strings and int')]
    #[DataSet([[true, 'x'], '[true,"x"]'], 'bool and string')]
    #[DataSet([[1.5, 'x'], '[1.5,"x"]'], 'float and string')]
    #[DataSet([['a', 1, null], '["a",1,null]'], 'mixed with null')]
    #[DataSet([['a', ['b']], '["a",["b"]]'], 'string and nested list')]
    #[DataSet([[['a'], ['b', 'c']], '[["a"],["b","c"]]'], 'nested lists')]
    #[DataSet([[new AttributeText('a'), 1], '["a",1]'], 'Stringable and int')]
    #[DataSet([[AttributeSize::Small, AttributePriority::High], '["Small",42]'], 'unit enum and int enum')]
    #[DataSet([[new \stdClass(), 1], '["stdClass",1]'], 'object and int')]
    #[DataSet([['k' => 'v'], '{"k":"v"}'], 'map')]
    #[DataSet([['k' => null], '{"k":null}'], 'map with null')]
    #[DataSet([[1 => 'x'], '{"1":"x"}'], 'int key not zero')]
    #[DataSet([[1 => 'a', 2 => 'b'], '{"1":"a","2":"b"}'], 'list with a hole')]
    #[DataSet([['k' => ['a', 1]], '{"k":["a",1]}'], 'map of mixed list')]
    #[DataSet([['k' => AttributeStatus::Paid, 't' => new AttributeText('x')], '{"k":"paid","t":"x"}'], 'map of objects')]
    #[DataSet(
        [['at' => new \DateTimeImmutable('2024-02-29T13:14:15+03:00')], '{"at":"2024-02-29T13:14:15+03:00"}'],
        'map of date',
    )]
    public function arrayThatIsNotListOfOneTypeIsExportedAsJson(array $value, string $expected): void
    {
        $this->assertExported($value, $expected);
    }

    #[DataSet([AttributeStatus::Paid, 'paid'], 'string enum')]
    #[DataSet([AttributePriority::High, 42], 'int enum')]
    #[DataSet([AttributeSize::Small, 'Small'], 'unit enum')]
    #[DataSet([new \DateTimeImmutable('2024-02-29T13:14:15+03:00'), '2024-02-29T13:14:15+03:00'], 'date')]
    #[DataSet([new \DateTime('1999-12-31T23:59:59Z'), '1999-12-31T23:59:59+00:00'], 'mutable date in UTC')]
    #[DataSet([new \DateTimeImmutable('2024-02-29T13:14:15.25Z'), '2024-02-29T13:14:15.250000+00:00'], 'date with fraction')]
    public function enumAndDateAreExportedAsTheirValue(object $value, int|string $expected): void
    {
        $this->assertExported($value, $expected);
    }

    public function nullAttributeIsNotExported(): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setAttribute('value', null));

        Assert::same($span->getAttributes()->toArray(), []);
    }

    public function resourceAttributeIsDescribedByType(): void
    {
        $resource = \fopen('php://memory', 'rb');
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setAttribute('resource', $resource));
        \fclose($resource);

        Assert::same($span->getAttributes()->toArray()['resource'], 'resource (stream)');
    }

    #[DataSet([null, SpanKind::KIND_INTERNAL], 'default')]
    #[DataSet([TraceKind::INTERNAL, SpanKind::KIND_INTERNAL], 'internal')]
    #[DataSet([TraceKind::CLIENT, SpanKind::KIND_CLIENT], 'client')]
    #[DataSet([TraceKind::SERVER, SpanKind::KIND_SERVER], 'server')]
    #[DataSet([TraceKind::PRODUCER, SpanKind::KIND_PRODUCER], 'producer')]
    #[DataSet([TraceKind::CONSUMER, SpanKind::KIND_CONSUMER], 'consumer')]
    public function traceKindIsMapped(?TraceKind $traceKind, int $expected): void
    {
        $this->createTracer()->trace('test', static fn() => null, traceKind: $traceKind);

        Assert::same($this->exporter->getSpans()[0]->getKind(), $expected);
    }

    public function startTimeIsKept(): void
    {
        $this->createTracer()->trace('test', static fn() => null, startTime: 1_700_000_000_000_000_000);

        Assert::same($this->exporter->getSpans()[0]->getStartEpochNanos(), 1_700_000_000_000_000_000);
    }

    public function exceptionIsRethrown(): never
    {
        $tracer = $this->createTracer();

        Expect::exception(\RuntimeException::class)->withMessage('Boom');

        $tracer->trace('test', static fn() => throw new \RuntimeException('Boom'));
    }

    public function exceptionIsRecordedAsEvent(): void
    {
        try {
            $this->createTracer()->trace('test', static fn() => throw new \RuntimeException('Boom'));
        } catch (\RuntimeException) {
            # Rethrowing is checked by exceptionIsRethrown(); this test only needs the span.
        }

        $events = $this->exporter->getSpans()[0]->getEvents();
        Assert::count($events, 1);
        Assert::same($events[0]->getName(), 'exception');
    }

    public function throwingCallbackKeepsNameAndAttributesAndMarksSpanAsError(): void
    {
        $span = $this->traceThrowing(static function (SpanInterface $span): never {
            $span->updateName('renamed')->setAttribute('k', 'v');
            throw new \RuntimeException('Boom');
        });

        Assert::same($span->getName(), 'renamed');
        Assert::same($span->getAttributes()->toArray(), ['k' => 'v']);
        Assert::same($span->getStatus()->getCode(), StatusCode::STATUS_ERROR);
        Assert::same($span->getStatus()->getDescription(), 'Boom');
    }

    #[DataSet(['ok', null, StatusCode::STATUS_OK, ''], 'Ok is final')]
    #[DataSet(['error', 'Card expired', StatusCode::STATUS_ERROR, 'Card expired'], 'Error keeps its description')]
    #[DataSet(['unset', null, StatusCode::STATUS_ERROR, 'Boom'], 'Unset gives way')]
    public function statusSetBeforeTheThrowIsRespected(
        string $code,
        ?string $description,
        string $expectedCode,
        string $expectedDescription,
    ): void {
        $span = $this->traceThrowing(static function (SpanInterface $span) use ($code, $description): never {
            $span->setStatus($code, $description);
            throw new \RuntimeException('Boom');
        });

        Assert::same($span->getStatus()->getCode(), $expectedCode);
        Assert::same($span->getStatus()->getDescription(), $expectedDescription);
    }

    public function traceStateIsPropagated(): void
    {
        $tracer = $this->createTracer([
            'traceparent' => self::TRACEPARENT,
            'tracestate' => 'vendor=value',
        ]);

        $tracer->trace('test', static fn() => null);

        Assert::same($tracer->getContext()['tracestate'] ?? null, 'vendor=value');
    }

    public function severalTraceStateFieldsAreCombined(): void
    {
        $tracer = $this->createTracer([
            'traceparent' => [self::TRACEPARENT],
            'tracestate' => ['a=1', 'b=2'],
        ]);

        $tracer->trace('test', static fn() => null);

        Assert::same((string) $this->exporter->getSpans()[0]->getContext()->getTraceState(), 'a=1,b=2');
        Assert::same($tracer->getContext()['tracestate'] ?? null, 'a=1,b=2');
    }

    /**
     * W3C Trace Context: a tracestate with an invalid list-member or more than 32 members is discarded whole,
     * also when the offending member comes from a later header field.
     */
    #[DataSet([['a=1', 'b']], 'invalid member in a later field')]
    #[DataSet([['m0=0,m1=1,m2=2,m3=3,m4=4,m5=5,m6=6,m7=7,m8=8,m9=9,m10=10,m11=11,m12=12,m13=13,m14=14,m15=15', 'm16=16,m17=17,m18=18,m19=19,m20=20,m21=21,m22=22,m23=23,m24=24,m25=25,m26=26,m27=27,m28=28,m29=29,m30=30,m31=31', 'm32=32']], '33 members over three fields')]
    public function invalidCombinedTraceStateIsDiscarded(array $tracestate): void
    {
        $tracer = $this->createTracer([
            'traceparent' => [self::TRACEPARENT],
            'tracestate' => $tracestate,
        ]);

        # The SDK warns about the discarded tracestate; keep it off stderr, where Infection stops the run.
        $log = new class implements LogWriterInterface {
            /** @var list<string> */
            public array $levels = [];

            #[\Override]
            public function write($level, string $message, array $context): void
            {
                $this->levels[] = (string) $level;
            }
        };
        Logging::setLogWriter($log);

        # The incoming context is extracted once, so the warning comes once however often it is read.
        $inside = [];
        try {
            $tracer->trace('test', static function () use ($tracer, &$inside): void {
                $inside = $tracer->getContext();
            });
        } finally {
            Logging::reset();
        }

        Assert::same((string) $this->exporter->getSpans()[0]->getContext()->getTraceState(), '');
        Assert::false(isset($inside['tracestate']));
        Assert::false(isset($tracer->getContext()['tracestate']));
        Assert::same($log->levels, ['warning']);
    }

    public function propagatedContextBecomesParent(): void
    {
        $span = $this->traceOne(
            static fn() => null,
            context: ['traceparent' => self::TRACEPARENT],
        );

        Assert::same($span->getTraceId(), self::TRACE_ID);
        Assert::same($span->getParentSpanId(), self::PARENT_SPAN_ID);
    }

    public function contextIsReturnedUntilFirstTrace(): void
    {
        $context = ['traceparent' => self::TRACEPARENT];

        Assert::same($this->createTracer($context)->getContext(), $context);
    }

    public function contextPointsToLastSpanAfterTrace(): void
    {
        $tracer = $this->createTracer();

        $tracer->trace('test', static fn() => null);

        $span = $this->exporter->getSpans()[0];
        Assert::same(
            $tracer->getContext(),
            ['traceparent' => '00-' . $span->getTraceId() . '-' . $span->getSpanId() . '-01'],
        );
    }

    public function contextPointsToOuterSpanAfterNestedTrace(): void
    {
        $tracer = $this->createTracer();
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, &$headers): void {
            $tracer->trace('inner', static fn() => null, scoped: true);
            $headers[] = $tracer->getContext();
        }, scoped: true);
        $headers[] = $tracer->getContext();

        $outer = $this->exporter->getSpans()[1];
        Assert::same($outer->getName(), 'outer');
        Assert::same($headers, \array_fill(0, 2, ['traceparent' => self::traceparentOf($outer)]));
    }

    /**
     * A span other instrumentation activates inside a scoped trace is where the code runs; inside a trace
     * that is not scoped it is not, as it hangs on the active span outside the trace.
     */
    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextInsideTraceRespectsSpanActivatedByOthers(bool $scoped): void
    {
        $tracer = $this->createTracer();
        $foreign = $this->provider->getTracer('foreign');
        $context = [];

        $tracer->trace('own', static function () use ($tracer, $foreign, &$context): void {
            $hook = $foreign->spanBuilder('hook')->startSpan();
            $scope = $hook->activate();
            try {
                $context = $tracer->getContext();
            } finally {
                $scope->detach();
                $hook->end();
            }
        }, scoped: $scoped);

        [$hook, $own] = $this->exporter->getSpans();
        Assert::same($context, ['traceparent' => self::traceparentOf($scoped ? $hook : $own)]);
    }

    /**
     * The incoming baggage is current inside a scoped trace that takes the remote parent, so the code
     * reads and extends it; outside, nothing is activated.
     */
    #[DataSet([true, 'acme'], 'scoped')]
    #[DataSet([false, null], 'not scoped')]
    public function incomingBaggageIsCurrentInsideScopedRoot(bool $scoped, ?string $expected): void
    {
        $tracer = $this->createBaggageTracer();
        $seen = 'none';

        $tracer->trace('root', static function () use (&$seen): void {
            $seen = Baggage::getCurrent()->getValue('tenant');
        }, scoped: $scoped);

        Assert::same($seen, $expected);
    }

    /**
     * A nested trace has a local parent, so the baggage active in it is whatever the code made current.
     */
    public function incomingBaggageIsNotActivatedForNestedTrace(): void
    {
        $tracer = $this->createBaggageTracer();
        $seen = 'none';

        $tracer->trace('root', static function () use ($tracer, &$seen): void {
            $scope = Baggage::getBuilder()->set('user', 'alice')->build()->activate();
            try {
                $tracer->trace('nested', static function () use (&$seen): void {
                    $seen = \array_keys(\iterator_to_array(Baggage::getCurrent()->getAll()));
                }, scoped: true);
            } finally {
                $scope->detach();
            }
        }, scoped: true);

        Assert::same($seen, ['user']);
    }

    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextOfRunningTraceCarriesIncomingBaggage(bool $scoped): void
    {
        $tracer = $this->createBaggageTracer();
        $context = [];

        $tracer->trace('root', static function () use ($tracer, &$context): void {
            $context = $tracer->getContext();
        }, scoped: $scoped);

        Assert::same($context['baggage'] ?? null, 'tenant=acme');
    }

    public function contextCarriesBaggageTheCodeActivated(): void
    {
        $tracer = $this->createBaggageTracer();
        $context = [];

        $tracer->trace('root', static function () use ($tracer, &$context): void {
            $scope = Baggage::getBuilder()->set('user', 'alice')->build()->activate();
            try {
                $context = $tracer->getContext();
            } finally {
                $scope->detach();
            }
        });

        Assert::same($context['baggage'] ?? null, 'user=alice');
    }

    /**
     * After the trace, as for the response headers, only the trace context goes back.
     */
    public function contextAfterTraceCarriesNoBaggage(): void
    {
        $tracer = $this->createBaggageTracer();

        $tracer->trace('root', static fn() => null, scoped: true);

        Assert::same(\array_keys($tracer->getContext()), ['traceparent']);
    }

    public function contextWithoutIncomingContextCarriesNoBaggage(): void
    {
        $tracer = $this->createTracer(
            propagator: new MultiTextMapPropagator([TraceContextPropagator::getInstance(), BaggagePropagator::getInstance()]),
        );
        $context = [];

        $tracer->trace('root', static function () use ($tracer, &$context): void {
            $context = $tracer->getContext();
        }, scoped: true);

        Assert::same(\array_keys($context), ['traceparent']);
    }

    /**
     * Traces in fibers sharing a tracer end out of order: the one started first ends first here.
     */
    public function contextFollowsTracesEndingOutOfOrder(): void
    {
        $tracer = $this->createTracer();
        $fiber = new \Fiber(static function () use ($tracer): void {
            # The context storage does not fork into a fiber on its own.
            $scope = Context::storage()->attach(Context::getRoot());
            try {
                $tracer->trace('first', static fn() => \Fiber::suspend());
            } finally {
                $scope->detach();
            }
        });
        $fiber->start();
        $context = [];

        $tracer->trace('second', static function () use ($fiber, $tracer, &$context): void {
            $fiber->resume();
            $context = $tracer->getContext();
        });

        [$first, $second] = $this->exporter->getSpans();
        Assert::same($first->getName(), 'first');
        Assert::same($context, ['traceparent' => self::traceparentOf($second)]);
        Assert::same($tracer->getContext(), ['traceparent' => self::traceparentOf($second)]);
    }

    public function contextFallsBackToScopedTraceWhenNoSpanIsActive(): void
    {
        $tracer = $this->createTracer();
        $context = [];

        $tracer->trace('own', static function () use ($tracer, &$context): void {
            $scope = Context::getRoot()->activate();
            try {
                $context = $tracer->getContext();
            } finally {
                $scope->detach();
            }
        }, scoped: true);

        Assert::same($context, ['traceparent' => self::traceparentOf($this->exporter->getSpans()[0])]);
    }

    private static function traceparentOf(SpanDataInterface $span): string
    {
        return '00-' . $span->getTraceId() . '-' . $span->getSpanId() . '-01';
    }

    /**
     * An empty array wrapped in $levels more arrays.
     */
    private static function nest(int $levels): array
    {
        return $levels === 0 ? [] : [self::nest($levels - 1)];
    }

    private function createTracer(array $context = [], ?TextMapPropagatorInterface $propagator = null): Tracer
    {
        return new Tracer(
            new Container(),
            $this->provider->getTracer('test'),
            $propagator ?? TraceContextPropagator::getInstance(),
            $context,
        );
    }

    private function createBaggageTracer(): Tracer
    {
        return $this->createTracer(
            ['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=acme'],
            new MultiTextMapPropagator([TraceContextPropagator::getInstance(), BaggagePropagator::getInstance()]),
        );
    }

    private function assertExported(mixed $value, mixed $expected): void
    {
        $span = $this->traceOne(static fn(SpanInterface $span) => $span->setAttribute('value', $value));

        Assert::same($span->getAttributes()->toArray(), ['value' => $expected]);
        Assert::same($span->getAttributes()->getDroppedAttributesCount(), 0);
    }

    private function traceThrowing(\Closure $callback): SpanDataInterface
    {
        try {
            $this->createTracer()->trace('test', $callback);
        } catch (\RuntimeException) {
            # Rethrowing is checked by exceptionIsRethrown(); this test only needs the span.
        }

        $spans = $this->exporter->getSpans();
        Assert::count($spans, 1);

        return $spans[0];
    }

    private function traceOne(\Closure $callback, array $context = []): SpanDataInterface
    {
        $this->createTracer($context)->trace('test', $callback);

        $spans = $this->exporter->getSpans();
        Assert::count($spans, 1);

        return $spans[0];
    }
}
