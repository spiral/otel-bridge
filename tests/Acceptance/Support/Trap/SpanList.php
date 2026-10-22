<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Testo\Assert;

/**
 * Spans received for one test, in arrival order.
 *
 * @implements \IteratorAggregate<int, ReceivedSpan>
 */
final readonly class SpanList implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ReceivedSpan> $spans
     */
    public function __construct(
        public array $spans,
    ) {}

    /**
     * The only span with the given name; fails the test when there is none or several.
     */
    public function named(string $name): ReceivedSpan
    {
        $found = \array_values(\array_filter($this->spans, static fn(ReceivedSpan $s): bool => $s->name === $name));
        \count($found) === 1 or Assert::fail(\sprintf(
            "Expected exactly one span named \"%s\", got %d. Received:\n%s",
            $name,
            \count($found),
            $this,
        ));

        return $found[0];
    }

    /**
     * The only SERVER span. Its name is up to spiral/http: `{method} {url}`, or `{method}` once it follows
     * the stable HTTP conventions.
     */
    public function server(): ReceivedSpan
    {
        $found = \array_values(\array_filter($this->spans, static fn(ReceivedSpan $s): bool => $s->kind === ReceivedSpan::KIND_SERVER));
        \count($found) === 1 or Assert::fail(\sprintf(
            "Expected exactly one SERVER span, got %d. Received:\n%s",
            \count($found),
            $this,
        ));

        return $found[0];
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->spans);
    }

    public function count(): int
    {
        return \count($this->spans);
    }

    public function __toString(): string
    {
        return $this->spans === []
            ? '  (none)'
            : \implode("\n", \array_map(static fn(ReceivedSpan $s): string => "  {$s}", $this->spans));
    }
}
