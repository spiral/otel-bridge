<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Container\ContainerInterface;
use Spiral\Exceptions\ExceptionReporterInterface;
use Spiral\Telemetry\ClockInterface;

/**
 * Flushes the tracer provider when a request or job ends and shuts it down with the app, when the app owns
 * it. A provider taken from the SDK autoloader is only flushed: the SDK shuts it down at process exit,
 * after the spans other shutdown functions and destructors may still end.
 *
 * The end of a request or job flushes after every `$flushEvery` of them, or once `$flushInterval` seconds
 * have passed since the last flush; with 0 and no interval, only shutdown does. Both are counted per
 * worker process.
 *
 * Runs inside the request, the kernel destructor and the shutdown functions, so it never throws: a failure
 * goes to the exception reporter, and a failure of the reporter to the SDK log.
 *
 * @internal
 */
final class TracerProviderFinalizer
{
    use LogsMessagesTrait;

    private readonly ?ClockInterface $clock;
    private readonly int $flushIntervalNs;
    private int $unflushed = 0;
    private int $flushedAt = 0;

    /**
     * @param int<0, max> $flushEvery
     * @param int|float|null $flushInterval Seconds, positive; the clock is taken from the container for it.
     */
    public function __construct(
        private readonly TracerProviderInterface $provider,
        private readonly ContainerInterface $container,
        private readonly bool $owned = true,
        private readonly int $flushEvery = 1,
        int|float|null $flushInterval = null,
    ) {
        $this->flushIntervalNs = (int) ((float) $flushInterval * 1e9);
        if ($flushInterval === null) {
            $this->clock = null;
            return;
        }

        $clock = $container->get(ClockInterface::class);
        \assert($clock instanceof ClockInterface);
        $this->clock = $clock;
        $this->flushedAt = $clock->now();
    }

    public function shutdown(): void
    {
        $this(terminate: true);
    }

    public function __invoke(bool $terminate = false): void
    {
        if (!$terminate && !$this->flushDue()) {
            return;
        }

        # Shutdown closes the processor for good, so the end of a request only flushes.
        # `false` is not worth a report: failed exports are logged by the SDK, and a closed processor
        # answers `false` too.
        try {
            # @infection-ignore-all Killed only by the tests of process exit, which run the unmutated src.
            UnsampledContext::run(fn(): bool => $terminate && $this->owned
                ? $this->provider->shutdown()
                : $this->provider->forceFlush());
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    private function flushDue(): bool
    {
        ++$this->unflushed;
        $now = $this->clock?->now();
        $due = $this->flushEvery > 0 && $this->unflushed >= $this->flushEvery
            || $now !== null && $now - $this->flushedAt >= $this->flushIntervalNs;

        if ($due) {
            $this->unflushed = 0;
            $this->flushedAt = $now ?? 0;
        }

        return $due;
    }

    /**
     * The reporter is resolved only now: one that depends on the tracer could not be built with the provider.
     * It is gone too when the kernel destructor runs as part of destroying the container.
     */
    private function report(\Throwable $e): void
    {
        try {
            $reporter = $this->container->get(ExceptionReporterInterface::class);
            \assert($reporter instanceof ExceptionReporterInterface);
            $reporter->report($e);
        } catch (\Throwable $reporterError) {
            self::logError(
                "Unable to export spans; could not report the failure: {$reporterError->getMessage()}",
                ['exception' => $e],
            );
        }
    }
}
