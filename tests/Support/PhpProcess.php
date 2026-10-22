<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Support;

use Testo\Assert;

/**
 * Runs a script of `tests/Process` in a PHP subprocess, for what only a process of its own shows: process
 * exit, `Globals` and the hooks of ext-opentelemetry, which are per process.
 */
final class PhpProcess
{
    /** Seconds the child process gets to exit. */
    private const TIMEOUT = 30;

    /**
     * @param non-empty-string $script File name in `tests/Process`.
     * @param array<string, string> $env Added to the env of this process, which the child inherits.
     * @param list<string> $args
     *
     * @return array{list<string>, string, int} Lines of stdout, stderr and the exit code.
     */
    public static function run(string $script, array $env = [], array $args = []): array
    {
        # Files, not pipes: a child that fills the pipe nobody reads yet would block forever.
        $out = (string) \tempnam(\sys_get_temp_dir(), 'otel');
        $err = (string) \tempnam(\sys_get_temp_dir(), 'otel');

        try {
            $process = \proc_open(
                [\PHP_BINARY, __DIR__ . '/../Process/' . $script, ...$args],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                $pipes,
                null,
                [...\getenv(), ...$env],
            );
            \assert(\is_resource($process));
            \fclose($pipes[0]);
            $deadline = \microtime(true) + self::TIMEOUT;
            while (($status = \proc_get_status($process))['running']) {
                if (\microtime(true) > $deadline) {
                    \proc_terminate($process);
                    \proc_close($process);
                    Assert::fail(\sprintf('The child process did not exit within %d s.', self::TIMEOUT));
                }
                \usleep(20_000);
            }
            # Only the first status call after the exit carries the code; proc_close() would return -1.
            $code = $status['exitcode'];
            \proc_close($process);

            return [\preg_split('/\R/', \trim((string) \file_get_contents($out))), (string) \file_get_contents($err), $code];
        } finally {
            @\unlink($out);
            @\unlink($err);
        }
    }
}
