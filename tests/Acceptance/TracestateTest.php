<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * Incoming `tracestate` as PSR-7 hands it over: one header field is a string or a list of one value,
 * several header fields are a list of several values.
 *
 * Several `tracestate` header fields are one list-member list, combined as RFC 9110 combines repeated fields:
 * https://www.w3.org/TR/trace-context/#tracestate-header
 */
#[Test]
final class TracestateTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01';

    #[DataSet([['congo=t61rcWkgMzE', 'rojo=00f067aa0ba902b7'], 'congo=t61rcWkgMzE,rojo=00f067aa0ba902b7'], 'two fields')]
    #[DataSet([['a=1', 'b=2,c=3', 'd=4'], 'a=1,b=2,c=3,d=4'], 'three fields, one with two members')]
    public function severalTracestateFieldsAreCombined(array $tracestate, string $expected): void
    {
        $this->assertTracestateCarried(['traceparent' => [self::TRACEPARENT], 'tracestate' => $tracestate], $expected);
    }

    /**
     * One header field already carrying several list-members; a fix combining fields must keep it as is.
     */
    #[DataSet(['congo=t61rcWkgMzE,rojo=00f067aa0ba902b7'], 'string')]
    #[DataSet([['congo=t61rcWkgMzE,rojo=00f067aa0ba902b7']], 'list of one')]
    public function singleTracestateFieldIsKept(string|array $tracestate): void
    {
        $this->assertTracestateCarried(
            ['traceparent' => [self::TRACEPARENT], 'tracestate' => $tracestate],
            'congo=t61rcWkgMzE,rojo=00f067aa0ba902b7',
        );
    }

    /**
     * Traces one span from the incoming headers and checks that both the exported span and `getContext()`
     * taken after it carry the expected tracestate.
     */
    private function assertTracestateCarried(array $incoming, string $expected): void
    {
        $tracer = $this->tracerWithContext($incoming);

        $tracer->trace('incoming', static fn() => null, scoped: true);
        $context = $tracer->getContext();

        $span = $this->traces()->await(1)->named('incoming');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($span->traceState, $expected);
        Assert::same($context['tracestate'] ?? null, $expected);
    }
}
