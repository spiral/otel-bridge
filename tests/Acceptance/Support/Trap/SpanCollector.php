<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Testo\Assert;

/**
 * Reads the spans Trap dumped for one service name.
 *
 * Every test exports under a service name of its own, so spans a previous test exported late cannot
 * leak into the next one, and the dump files are shared by the whole suite.
 */
final class SpanCollector
{
    private const FRAME_TYPE = 'otlp.traces';

    /** @var array<string, int> Bytes of each dump file to ignore, set by {@see reset()}. */
    private array $offsets = [];

    public function __construct(
        private readonly string $directory,
        public readonly string $serviceName,
    ) {}

    /**
     * Ignores everything received so far.
     */
    public function reset(): void
    {
        foreach ($this->files() as $file) {
            $this->offsets[$file] = \strlen($this->completeLines($file, 0));
        }
    }

    /**
     * What has arrived by now, without waiting.
     */
    public function received(): SpanList
    {
        $spans = [];
        foreach ($this->files() as $file) {
            $content = $this->completeLines($file, $this->offsets[$file] ?? 0);
            foreach (\explode("\n", $content) as $line) {
                $line === '' or \array_push($spans, ...$this->parseLine($line));
            }
        }

        \usort($spans, static fn(ReceivedSpan $a, ReceivedSpan $b): int => $a->startTimeUnixNano <=> $b->startTimeUnixNano);

        return new SpanList($spans);
    }

    /**
     * Polls the dumps until at least `$count` spans arrived, then waits `$settle` seconds more so that
     * extra spans show up and an exact count can be asserted. Fails the test on timeout.
     */
    public function await(int $count, float $timeout = 5.0, float $settle = 0.3): SpanList
    {
        $deadline = \microtime(true) + $timeout;
        do {
            if (\count($this->received()) >= $count) {
                \usleep((int) ($settle * 1_000_000));
                return $this->received();
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        Assert::fail(\sprintf(
            "Expected %d span(s) for service \"%s\" within %.1fs. Received:\n%s",
            $count,
            $this->serviceName,
            $timeout,
            $this->received(),
        ));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = \glob($this->directory . \DIRECTORY_SEPARATOR . 'dump-*.log');
        if ($files === false) {
            return [];
        }

        \sort($files);
        return $files;
    }

    /**
     * Trap may be appending to the file while it is read: a line without its trailing newline is not
     * complete yet and is left for the next poll.
     */
    private function completeLines(string $file, int $offset): string
    {
        $content = @\file_get_contents($file, offset: $offset);
        if ($content === false) {
            return '';
        }

        $end = \strrpos($content, "\n");

        return $end === false ? '' : \substr($content, 0, $end + 1);
    }

    /**
     * @return list<ReceivedSpan>
     */
    private function parseLine(string $line): array
    {
        $frame = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($frame) || ($frame['type'] ?? null) !== self::FRAME_TYPE) {
            return [];
        }

        $spans = [];
        foreach ((array) ($frame['resourceSpans'] ?? []) as $resourceSpans) {
            foreach ((array) ($resourceSpans['scopeSpans'] ?? []) as $scopeSpans) {
                foreach ((array) ($scopeSpans['spans'] ?? []) as $span) {
                    $received = ReceivedSpan::fromDump($resourceSpans, $scopeSpans, $span);
                    $received->serviceName() === $this->serviceName and $spans[] = $received;
                }
            }
        }

        return $spans;
    }
}
