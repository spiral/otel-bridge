<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use Psr\Log\LogLevel;

/**
 * Records what the OpenTelemetry SDK reports through its internal logger instead of writing it to `error_log`.
 */
final class ExporterLifecycleLogWriter implements LogWriterInterface
{
    private const PROBLEMS = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    /** @var list<array{level: string, message: string, exception: ?\Throwable}> */
    public array $entries = [];

    #[\Override]
    public function write($level, string $message, array $context): void
    {
        $exception = $context['exception'] ?? null;
        $this->entries[] = [
            'level' => (string) $level,
            'message' => $message,
            'exception' => $exception instanceof \Throwable ? $exception : null,
        ];
    }

    /**
     * Entries of level warning and above, as `level: message [exception message]` lines.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $lines = [];
        foreach ($this->entries as $entry) {
            if (\in_array($entry['level'], self::PROBLEMS, true)) {
                $lines[] = \sprintf(
                    '%s: %s%s',
                    $entry['level'],
                    $entry['message'],
                    $entry['exception'] === null ? '' : " [{$entry['exception']->getMessage()}]",
                );
            }
        }

        return $lines;
    }
}
