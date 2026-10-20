<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use OpenTelemetry\API\Baggage\Baggage;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\HttpBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanList;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * What `Tracer::getContext()` hands to the next hop: the headers are taken at a point of the trace and a
 * "downstream" trace is started from them through the tracer factory, the way a queue consumer or a
 * called service does; the downstream span Trap receives must be a child of the span running at that point.
 *
 * The injected `traceparent` carries the caller's current span as parent-id:
 * https://www.w3.org/TR/trace-context/#parent-id and
 * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/context/api-propagators.md#inject
 */
#[Test]
final class ContextPropagationTest extends TestCase
{
    private const TRACE_ID = '5c1d0e0f3a9b4b6c8d7e6f5a4b3c2d1e';
    private const REMOTE_SPAN_ID = 'a2fb4a1d1a96d312';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01';
    private const TRACESTATE = 'vendor=value,other=1';
    private const INCOMING = ['traceparent' => self::TRACEPARENT];

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), HttpBootloader::class];
    }

    #[DataSet([[], true], 'no incoming, scoped')]
    #[DataSet([[], false], 'no incoming, not scoped')]
    #[DataSet([self::INCOMING, true], 'incoming, scoped')]
    #[DataSet([self::INCOMING, false], 'incoming, not scoped')]
    public function contextInsideTracePointsToThatTrace(array $incoming, bool $scoped): void
    {
        $tracer = $this->tracerWithContext($incoming);
        $headers = [];

        $tracer->trace('current', static function () use ($tracer, &$headers): void {
            $headers['inside'] = $tracer->getContext();
        }, scoped: $scoped);

        $spans = $this->continueAndAwait($headers, 1);
        self::assertContinues($spans, 'inside', 'current');
    }

    #[DataSet([[], true], 'no incoming, scoped')]
    #[DataSet([[], false], 'no incoming, not scoped')]
    #[DataSet([self::INCOMING, true], 'incoming, scoped')]
    #[DataSet([self::INCOMING, false], 'incoming, not scoped')]
    public function contextInsideOuterAfterNestedTracePointsToOuter(array $incoming, bool $scoped): void
    {
        $tracer = $this->tracerWithContext($incoming);
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, $scoped, &$headers): void {
            $tracer->trace('inner', static function () use ($tracer, &$headers): void {
                $headers['in inner'] = $tracer->getContext();
            }, scoped: $scoped);
            $headers['in outer after inner'] = $tracer->getContext();
        }, scoped: $scoped);
        $headers['after outer'] = $tracer->getContext();

        $spans = $this->continueAndAwait($headers, 2);
        self::assertContinues($spans, 'in inner', 'inner');
        self::assertContinues($spans, 'in outer after inner', 'outer');
        self::assertContinues($spans, 'after outer', 'outer');
    }

    /**
     * Not scoped spans are never activated, so a fix reading the active span from `Context::getCurrent()`
     * would lose them; the tracer has to track its own spans.
     */
    #[DataSet([true, false], 'scoped outer, not scoped inner')]
    #[DataSet([false, true], 'not scoped outer, scoped inner')]
    #[DataSet([false, false], 'neither scoped')]
    public function contextInsideNestedTracePointsToNestedTraceWhateverIsActive(bool $outerScoped, bool $innerScoped): void
    {
        $tracer = $this->tracerWithContext([]);
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, $innerScoped, &$headers): void {
            $headers['in outer'] = $tracer->getContext();
            $tracer->trace('inner', static function () use ($tracer, &$headers): void {
                $headers['in inner'] = $tracer->getContext();
            }, scoped: $innerScoped);
        }, scoped: $outerScoped);

        $spans = $this->continueAndAwait($headers, 2);
        self::assertContinues($spans, 'in outer', 'outer');
        self::assertContinues($spans, 'in inner', 'inner');
    }

    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextBetweenSequentialSiblingsPointsToTheirParent(bool $scoped): void
    {
        $tracer = $this->tracerWithContext([]);
        $headers = [];

        $tracer->trace('parent', static function () use ($tracer, $scoped, &$headers): void {
            $tracer->trace('first', static fn() => null, scoped: $scoped);
            $headers['after first'] = $tracer->getContext();
            $tracer->trace('second', static function () use ($tracer, &$headers): void {
                $headers['in second'] = $tracer->getContext();
            }, scoped: $scoped);
            $headers['after second'] = $tracer->getContext();
        }, scoped: $scoped);

        $spans = $this->continueAndAwait($headers, 3);
        self::assertContinues($spans, 'after first', 'parent');
        self::assertContinues($spans, 'in second', 'second');
        self::assertContinues($spans, 'after second', 'parent');
    }

    /**
     * `spiral/queue` takes `getContext()` for the job headers before its `Job push` trace
     * (`Interceptor/Push/Core.php`), so the second job of a handler must not be linked to the first push.
     */
    public function jobsPushedOneAfterAnotherAreChildrenOfThePushingSpan(): void
    {
        $tracer = $this->tracerWithContext(self::INCOMING);
        $headers = [];

        $tracer->trace('handler', static function () use ($tracer, &$headers): void {
            foreach (['job-1', 'job-2', 'job-3'] as $job) {
                $headers[$job] = $tracer->getContext();
                $tracer->trace("Job push [{$job}]", static fn() => null);
            }
        }, scoped: true);

        $spans = $this->continueAndAwait($headers, 4);
        self::assertContinues($spans, 'job-1', 'handler');
        self::assertContinues($spans, 'job-2', 'handler');
        self::assertContinues($spans, 'job-3', 'handler');
    }

    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextAfterNestedTraceThrewPointsToOuter(bool $scoped): void
    {
        $tracer = $this->tracerWithContext([]);
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, $scoped, &$headers): void {
            try {
                $tracer->trace('failing', static function () use ($tracer, &$headers): never {
                    $headers['in failing'] = $tracer->getContext();
                    throw new \RuntimeException('Boom');
                }, scoped: $scoped);
            } catch (\RuntimeException) {
                $headers['in outer after failing'] = $tracer->getContext();
            }
        }, scoped: $scoped);
        $headers['after outer'] = $tracer->getContext();

        $spans = $this->continueAndAwait($headers, 2);
        self::assertContinues($spans, 'in failing', 'failing');
        self::assertContinues($spans, 'in outer after failing', 'outer');
        self::assertContinues($spans, 'after outer', 'outer');
    }

    public function contextAfterRootThrewOutOfNestedTracePointsToRoot(): void
    {
        $tracer = $this->tracerWithContext([]);

        try {
            $tracer->trace('root', static fn() => $tracer->trace(
                'nested',
                static fn() => throw new \RuntimeException('Boom'),
                scoped: true,
            ), scoped: true);
        } catch (\RuntimeException) {
            # The spans are what this test checks.
        }
        $headers = ['after root' => $tracer->getContext()];

        $spans = $this->continueAndAwait($headers, 2);
        self::assertContinues($spans, 'after root', 'root');
    }

    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextFollowsEveryLevelOfDeepNesting(bool $scoped): void
    {
        $tracer = $this->tracerWithContext([]);
        $headers = [];

        $tracer->trace('level-1', static function () use ($tracer, $scoped, &$headers): void {
            $headers['1 before 2'] = $tracer->getContext();
            $tracer->trace('level-2', static function () use ($tracer, $scoped, &$headers): void {
                $headers['2 before 3'] = $tracer->getContext();
                $tracer->trace('level-3', static function () use ($tracer, $scoped, &$headers): void {
                    $headers['3 before 4'] = $tracer->getContext();
                    $tracer->trace('level-4', static function () use ($tracer, &$headers): void {
                        $headers['in 4'] = $tracer->getContext();
                    }, scoped: $scoped);
                    $headers['3 after 4'] = $tracer->getContext();
                }, scoped: $scoped);
                $headers['2 after 3'] = $tracer->getContext();
            }, scoped: $scoped);
            $headers['1 after 2'] = $tracer->getContext();
        }, scoped: $scoped);
        $headers['after 1'] = $tracer->getContext();

        $spans = $this->continueAndAwait($headers, 4);
        self::assertContinues($spans, '1 before 2', 'level-1');
        self::assertContinues($spans, '2 before 3', 'level-2');
        self::assertContinues($spans, '3 before 4', 'level-3');
        self::assertContinues($spans, 'in 4', 'level-4');
        self::assertContinues($spans, '3 after 4', 'level-3');
        self::assertContinues($spans, '2 after 3', 'level-2');
        self::assertContinues($spans, '1 after 2', 'level-1');
        self::assertContinues($spans, 'after 1', 'level-1');
    }

    /**
     * `Spiral\Http\Http` returns `getContext()` in the response headers once the SERVER span is over:
     * a fix must keep the context of a finished root span.
     */
    #[DataSet([[]], 'no incoming')]
    #[DataSet([self::INCOMING], 'incoming')]
    public function contextAfterRootTracePointsToThatRoot(array $incoming): void
    {
        $tracer = $this->tracerWithContext($incoming);

        $tracer->trace('root', static fn() => null, scoped: true);
        $headers = ['after root' => $tracer->getContext()];

        $spans = $this->continueAndAwait($headers, 1);
        self::assertContinues($spans, 'after root', 'root');
    }

    /**
     * After every trace has ended, the last finished root is what continues: no spec defines it, and
     * `Spiral\Http\Http` relies on it.
     */
    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextAfterSequentialRootTracesPointsToTheLastOne(bool $scoped): void
    {
        $tracer = $this->tracerWithContext([]);

        $tracer->trace('first', static fn() => null, scoped: $scoped);
        $headers = ['after first' => $tracer->getContext()];
        $tracer->trace('second', static fn() => null, scoped: $scoped);
        $headers['after second'] = $tracer->getContext();

        $spans = $this->continueAndAwait($headers, 2);
        self::assertContinues($spans, 'after first', 'first');
        self::assertContinues($spans, 'after second', 'second');
        Assert::notSame($spans->named('first')->traceId, $spans->named('second')->traceId);
    }

    public function contextAfterSecondRootWithNestedTracePointsToSecondRoot(): void
    {
        $tracer = $this->tracerWithContext([]);

        $tracer->trace('first', static fn() => null, scoped: true);
        $tracer->trace('second', static fn() => $tracer->trace('nested', static fn() => null, scoped: true), scoped: true);
        $headers = ['after second' => $tracer->getContext()];

        $spans = $this->continueAndAwait($headers, 3);
        self::assertContinues($spans, 'after second', 'second');
    }

    public function contextBeforeAnyTraceIsTheIncomingContext(): void
    {
        $incoming = ['traceparent' => self::TRACEPARENT, 'tracestate' => self::TRACESTATE];
        $tracer = $this->tracerWithContext($incoming);

        $context = $tracer->getContext();

        $this->tracerWithContext($context)->trace('downstream', static fn() => null);
        $span = $this->traces()->await(1)->named('downstream');
        Assert::same($context, $incoming);
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
    }

    public function contextBeforeAnyTraceWithoutIncomingContextStartsNoTrace(): void
    {
        $tracer = $this->tracerWithContext([]);

        $context = $tracer->getContext();

        $this->tracerWithContext($context)->trace('downstream', static fn() => null);
        Assert::same($context, []);
        Assert::true($this->traces()->await(1)->named('downstream')->isRoot());
    }

    /**
     * `tracestate` travels with the trace: https://www.w3.org/TR/trace-context/#tracestate-header
     */
    #[DataSet([false], 'single trace')]
    #[DataSet([true], 'after a nested trace')]
    public function contextKeepsIncomingTracestate(bool $nested): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT, 'tracestate' => self::TRACESTATE]);
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, $nested, &$headers): void {
            $nested and $tracer->trace('inner', static fn() => null, scoped: true);
            $headers['in outer'] = $tracer->getContext();
        }, scoped: true);
        $headers['after outer'] = $tracer->getContext();

        Assert::same($headers['in outer']['tracestate'] ?? null, self::TRACESTATE);
        Assert::same($headers['after outer']['tracestate'] ?? null, self::TRACESTATE);
    }

    /**
     * `OTEL_PROPAGATORS` defaults to `tracecontext,baggage`:
     * https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#general-sdk-configuration
     */
    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function contextOfRunningTraceKeepsIncomingBaggage(bool $scoped): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=acme']);
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, &$headers): void {
            $headers['in outer'] = $tracer->getContext();
        }, scoped: $scoped);

        Assert::same($headers['in outer']['baggage'] ?? null, 'tenant=acme');
    }

    /**
     * The trace is over, as when `Spiral\Http\Http` adds the context to the response: the trace context
     * goes back, the baggage does not.
     */
    public function contextAfterTraceCarriesNoBaggage(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=acme']);

        $tracer->trace('outer', static fn() => null, scoped: true);

        Assert::same(\array_keys($tracer->getContext()), ['traceparent']);
    }

    /**
     * The incoming baggage is current inside the scoped root, so the code reads it and extends it.
     */
    public function incomingBaggageIsCurrentInsideScopedRootAndExtendable(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=acme']);
        $seen = null;
        $headers = [];

        $tracer->trace('outer', static function () use ($tracer, &$seen, &$headers): void {
            $seen = Baggage::getCurrent()->getValue('tenant');
            $scope = Baggage::getCurrent()->toBuilder()->set('user', 'alice')->build()->activate();
            try {
                $headers = $tracer->getContext();
            } finally {
                $scope->detach();
            }
        }, scoped: true);

        Assert::same($seen, 'acme');
        Assert::same($headers['baggage'] ?? null, 'tenant=acme,user=alice');
    }

    #[Env('OTEL_PROPAGATORS', 'tracecontext')]
    public function contextCarriesOnlyTheConfiguredPropagators(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=acme']);

        $tracer->trace('outer', static fn() => null, scoped: true);

        Assert::same(\array_keys($tracer->getContext()), ['traceparent']);
    }

    /**
     * Another tracer instance, as a nested request or job would get, keeps its spans to itself.
     */
    public function traceOfAnotherTracerDoesNotChangeTheContext(): void
    {
        $tracer = $this->tracerWithContext([]);
        $headers = [];

        $tracer->trace('outer', function () use ($tracer, &$headers): void {
            $this->tracerWithContext($tracer->getContext())->trace('foreign', static fn() => null, scoped: true);
            $headers['in outer after foreign'] = $tracer->getContext();
        }, scoped: true);
        $headers['after outer'] = $tracer->getContext();

        $spans = $this->continueAndAwait($headers, 2);
        Assert::same($spans->named('foreign')->parentSpanId, $spans->named('outer')->spanId);
        self::assertContinues($spans, 'in outer after foreign', 'outer');
        self::assertContinues($spans, 'after outer', 'outer');
    }

    /**
     * The response of `Spiral\Http\Http` carries `getContext()` taken after the SERVER span, while the
     * request handler of the fixture runs a nested `handler` trace.
     */
    #[DataSet([[]], 'no incoming')]
    #[DataSet([self::INCOMING], 'incoming')]
    public function responseTraceparentPointsToServerSpan(array $incoming): void
    {
        $response = $this->fakeHttp()->get('/ping', headers: $incoming);

        $traceparent = $response->getOriginalResponse()->getHeaderLine('traceparent');
        $this->tracerWithContext(['traceparent' => $traceparent])->trace('downstream', static fn() => null);
        $spans = $this->traces()->await(3);
        $server = $spans->named('GET /ping');
        Assert::same($traceparent, "00-{$server->traceId}-{$server->spanId}-01", "Received:\n{$spans}");
        Assert::same($spans->named('downstream')->parentSpanId, $server->spanId);
    }

    private static function assertContinues(SpanList $spans, string $point, string $expected): void
    {
        $downstream = $spans->named("downstream: {$point}");
        $parent = $spans->named($expected);
        $message = "Headers taken at \"{$point}\" must continue \"{$expected}\". Received:\n{$spans}";
        Assert::same($downstream->parentSpanId, $parent->spanId, $message);
        Assert::same($downstream->traceId, $parent->traceId, $message);
    }

    /**
     * Starts a downstream trace named `downstream: <point>` from each set of headers and waits for those
     * downstream spans and `$own` spans of the test.
     *
     * @param array<string, array<string, string>> $headers Headers taken at each point, keyed by the point.
     */
    private function continueAndAwait(array $headers, int $own): SpanList
    {
        foreach ($headers as $point => $context) {
            $this->tracerWithContext($context)->trace("downstream: {$point}", static fn() => null);
        }

        $spans = $this->traces()->await($own + \count($headers));
        Assert::count($spans, $own + \count($headers), "Received:\n{$spans}");

        return $spans;
    }
}
