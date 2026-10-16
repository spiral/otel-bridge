<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Testo\Assert;

/**
 * Reads the spans Trap dumped for one service name.
 *
 * Every test exports under a service name of its own, so spans a previous test exported late cannot
 * leak into the next one, and the dump files are shared by the whole suite.
 *
 * To know that nothing more is on the way, the collector sends Trap a barrier: a span of its own under
 * another service name. The SDK exports synchronously and Trap dumps a request before it answers it,
 * so once the barrier is in the dumps, everything exported before it is there too.
 */
final class SpanCollector
{
    private const FRAME_TYPE = 'otlp.traces';
    private const POLL_INTERVAL = 20_000;
    private const BARRIER_TIMEOUT = 5.0;

    /** @var array<string, int> Bytes of each dump file read so far. */
    private array $read = [];

    /** @var list<ReceivedSpan> Spans of the service read so far. */
    private array $spans = [];

    /** @var array<string, true> Barrier span ids seen in the dumps. */
    private array $barriers = [];

    /** Export requests of the service read so far. */
    private int $exports = 0;

    /**
     * @param string $endpoint Trap's OTLP/HTTP base URL, for the barrier.
     */
    public function __construct(
        private readonly string $directory,
        public readonly string $serviceName,
        private readonly string $endpoint,
    ) {
        # What the suite dumped before the test is never this service's; a line cut here cannot be either.
        foreach ($this->files() as $file) {
            $this->read[$file] = (int) \filesize($file);
        }
    }

    /**
     * Ignores everything received so far.
     */
    public function reset(): void
    {
        $this->poll();
        $this->spans = [];
    }

    /**
     * What has arrived by now, without waiting.
     */
    public function received(): SpanList
    {
        $this->poll();
        $spans = $this->spans;
        \usort($spans, static fn(ReceivedSpan $a, ReceivedSpan $b): int => $a->startTimeUnixNano <=> $b->startTimeUnixNano);

        return new SpanList($spans);
    }

    /**
     * Everything exported so far, once it has landed: for asserting that something was not exported.
     */
    public function settled(): SpanList
    {
        $this->barrier();

        return $this->received();
    }

    /**
     * Polls the dumps until at least `$count` spans arrived, then waits for the rest of what was exported
     * so far, so that an exact count can be asserted. Fails the test on timeout.
     */
    public function await(int $count, float $timeout = 5.0): SpanList
    {
        $deadline = \microtime(true) + $timeout;
        do {
            if (\count($this->received()) >= $count) {
                return $this->settled();
            }

            \usleep(self::POLL_INTERVAL);
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
     * Sends a barrier span and waits until Trap has dumped it.
     */
    private function barrier(): void
    {
        $spanId = \bin2hex(\random_bytes(8));
        $payload = \json_encode(['resourceSpans' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => "{$this->serviceName}.barrier"]],
            ]],
            'scopeSpans' => [['spans' => [[
                'traceId' => \bin2hex(\random_bytes(16)),
                'spanId' => $spanId,
                'name' => 'barrier',
                'startTimeUnixNano' => '1',
                'endTimeUnixNano' => '1',
            ]]]],
        ]]], \JSON_THROW_ON_ERROR);

        $response = @\file_get_contents("{$this->endpoint}/v1/traces", context: \stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => self::BARRIER_TIMEOUT,
            'ignore_errors' => true,
        ]]));
        $response === false and Assert::fail("Trap at {$this->endpoint} did not accept the barrier span.");

        $deadline = \microtime(true) + self::BARRIER_TIMEOUT;
        while (!isset($this->barriers[$spanId])) {
            \microtime(true) < $deadline or Assert::fail(\sprintf(
                'Trap did not dump the barrier span within %.1fs.',
                self::BARRIER_TIMEOUT,
            ));
            \usleep(self::POLL_INTERVAL);
            $this->poll();
        }

        unset($this->barriers[$spanId]);
    }

    /**
     * Reads the lines appended to the dumps since the last poll.
     */
    private function poll(): void
    {
        \clearstatcache();
        foreach ($this->files() as $file) {
            $offset = $this->read[$file] ?? 0;
            # Trap starts a new file for most writes: opening every file of the suite on each poll is slow.
            if ((int) @\filesize($file) <= $offset) {
                continue;
            }

            $content = $this->completeLines($file, $offset);
            if ($content === '') {
                continue;
            }

            $this->read[$file] = $offset + \strlen($content);
            foreach (\explode("\n", $content) as $line) {
                # Most lines belong to other tests: skip them without decoding.
                if ($line === '' || !\str_contains($line, $this->serviceName)) {
                    continue;
                }

                $this->parseLine($line);
            }
        }
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

    private function parseLine(string $line): void
    {
        $frame = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($frame) || ($frame['type'] ?? null) !== self::FRAME_TYPE) {
            return;
        }

        $export = ++$this->exports;
        foreach ((array) ($frame['resourceSpans'] ?? []) as $resourceSpans) {
            foreach ((array) ($resourceSpans['scopeSpans'] ?? []) as $scopeSpans) {
                foreach ((array) ($scopeSpans['spans'] ?? []) as $span) {
                    $received = ReceivedSpan::fromDump($resourceSpans, $scopeSpans, $span, $export);
                    match ($received->serviceName()) {
                        $this->serviceName => $this->spans[] = $received,
                        "{$this->serviceName}.barrier" => $this->barriers[$received->spanId] = true,
                        default => null,
                    };
                }
            }
        }
    }
}
