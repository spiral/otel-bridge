<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\Telemetry\ClockInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerInterface;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * Identity, kind and timing of the spans the Spiral tracer makes, as the collector receives them.
 *
 * Times are Unix epoch nanoseconds: https://opentelemetry.io/docs/specs/otlp/ (`fixed64 start_time_unix_nano`);
 * ids are random and non-zero: https://www.w3.org/TR/trace-context/#trace-id
 */
#[Test]
final class SpanModelTest extends TestCase
{
    private const NANOS_PER_MILLI = 1_000_000;

    /** How far span times may drift from `microtime()`: the SDK clock is `hrtime()` anchored once per process. */
    private const CLOCK_TOLERANCE = 1_000 * self::NANOS_PER_MILLI;

    /**
     * https://opentelemetry.io/docs/specs/otel/trace/api/#spankind
     */
    #[DataSet([null, ReceivedSpan::KIND_INTERNAL], 'no kind')]
    #[DataSet([TraceKind::INTERNAL, ReceivedSpan::KIND_INTERNAL], 'internal')]
    #[DataSet([TraceKind::SERVER, ReceivedSpan::KIND_SERVER], 'server')]
    #[DataSet([TraceKind::CLIENT, ReceivedSpan::KIND_CLIENT], 'client')]
    #[DataSet([TraceKind::PRODUCER, ReceivedSpan::KIND_PRODUCER], 'producer')]
    #[DataSet([TraceKind::CONSUMER, ReceivedSpan::KIND_CONSUMER], 'consumer')]
    public function traceKindArrivesAsItsOtlpKind(?TraceKind $kind, int $expected): void
    {
        $this->tracer()->trace('kind', static fn() => null, traceKind: $kind);

        Assert::same($this->traces()->await(1)->named('kind')->kind, $expected);
    }

    public function timestampsAreWallClockNanoseconds(): void
    {
        $before = self::wallClock();

        $this->tracer()->trace('timed', static fn() => null);

        $after = self::wallClock();
        $span = $this->traces()->await(1)->named('timed');
        Assert::int($span->startTimeUnixNano)->greaterThanOrEqual($before - self::CLOCK_TOLERANCE);
        Assert::int($span->endTimeUnixNano)->greaterThanOrEqual($span->startTimeUnixNano);
        Assert::int($span->endTimeUnixNano)->lessThanOrEqual($after + self::CLOCK_TOLERANCE);
    }

    public function durationCoversTimeSpentInCallback(): void
    {
        $this->tracer()->trace('sleeping', static fn() => \usleep(100_000));

        $span = $this->traces()->await(1)->named('sleeping');
        Assert::int($span->endTimeUnixNano - $span->startTimeUnixNano)
            ->greaterThanOrEqual(100 * self::NANOS_PER_MILLI)
            ->lessThan(2_000 * self::NANOS_PER_MILLI);
    }

    /**
     * `trace(startTime:)` takes nanoseconds of the clock Spiral binds, and the span keeps it as is.
     */
    public function explicitStartTimeIsKeptToTheNanosecond(): void
    {
        $startTime = $this->getContainer()->get(ClockInterface::class)->now() - 1_500 * self::NANOS_PER_MILLI;

        $this->tracer()->trace('backdated', static fn() => null, startTime: $startTime);

        $span = $this->traces()->await(1)->named('backdated');
        Assert::same($span->startTimeUnixNano, $startTime);
        Assert::int($span->endTimeUnixNano - $span->startTimeUnixNano)
            ->greaterThanOrEqual(1_500 * self::NANOS_PER_MILLI)
            ->lessThan(3_500 * self::NANOS_PER_MILLI);
    }

    /**
     * A child starts after and ends before its parent; sequential siblings do not overlap.
     */
    public function childTimesLieWithinParentAndSiblingsFollowEachOther(): void
    {
        $this->tracer()->trace('parent', static function (TracerInterface $tracer): void {
            $tracer->trace('first', static fn() => \usleep(10_000), scoped: true);
            $tracer->trace('second', static fn() => \usleep(10_000), scoped: true);
        }, scoped: true);

        $spans = $this->traces()->await(3);
        $parent = $spans->named('parent');
        $first = $spans->named('first');
        $second = $spans->named('second');
        Assert::same([$first->parentSpanId, $second->parentSpanId], [$parent->spanId, $parent->spanId]);
        Assert::int($first->startTimeUnixNano)->greaterThanOrEqual($parent->startTimeUnixNano);
        Assert::int($second->startTimeUnixNano)->greaterThanOrEqual($first->endTimeUnixNano);
        Assert::int($parent->endTimeUnixNano)->greaterThanOrEqual($second->endTimeUnixNano);
    }

    /**
     * Every span of a tree carries the root's trace id, a span id of its own and the sampled flag of a
     * local span (0x101: sampled, parent known not to be remote).
     */
    public function treeSharesTraceIdAndEachSpanHasItsOwnId(): void
    {
        $tracer = $this->tracer();

        $tracer->trace('root', static function () use ($tracer): void {
            $tracer->trace('a', static function () use ($tracer): void {
                $tracer->trace('a.1', static fn() => null, scoped: true);
                $tracer->trace('a.2', static fn() => null, scoped: true);
            }, scoped: true);
            $tracer->trace('b', static fn() => null, scoped: true);
            $tracer->trace('c', static fn() => $tracer->trace('c.1', static fn() => null, scoped: true), scoped: true);
        }, scoped: true);

        $spans = $this->traces()->await(7);
        $root = $spans->named('root');
        Assert::count($spans, 7, "Received:\n{$spans}");
        Assert::true($root->isRoot());
        Assert::string($root->traceId)->matchesRegex('/^[0-9a-f]{32}$/')->notMatchesRegex('/^0+$/');
        $parents = ['a' => 'root', 'a.1' => 'a', 'a.2' => 'a', 'b' => 'root', 'c' => 'root', 'c.1' => 'c'];
        foreach ($parents as $child => $parent) {
            Assert::same($spans->named($child)->parentSpanId, $spans->named($parent)->spanId, "parent of {$child}");
        }
        $ids = [];
        foreach ($spans as $span) {
            Assert::same($span->traceId, $root->traceId, "trace of {$span->name}");
            Assert::string($span->spanId)->matchesRegex('/^[0-9a-f]{16}$/')->notMatchesRegex('/^0+$/');
            Assert::same($span->flags, 0x101, "flags of {$span->name}");
            $ids[] = $span->spanId;
        }
        Assert::count(\array_unique($ids), 7);
    }

    public function sequentialRootTracesGetDistinctTraceIds(): void
    {
        $tracer = $this->tracer();

        for ($i = 0; $i < 50; ++$i) {
            $tracer->trace("trace-{$i}", static fn() => null);
        }

        $spans = $this->traces()->await(50);
        Assert::count($spans, 50, "Received:\n{$spans}");
        $traceIds = [];
        $spanIds = [];
        for ($i = 0; $i < 50; ++$i) {
            $span = $spans->named("trace-{$i}");
            Assert::true($span->isRoot(), "trace-{$i} is not a root");
            $traceIds[] = $span->traceId;
            $spanIds[] = $span->spanId;
        }
        Assert::count(\array_unique($traceIds), 50);
        Assert::count(\array_unique($spanIds), 50);
    }

    private static function wallClock(): int
    {
        return (int) (\microtime(true) * 1e9);
    }
}
