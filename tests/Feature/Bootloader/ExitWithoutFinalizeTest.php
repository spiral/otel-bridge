<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * A process that ends without finalize(true), as after a fatal error, still exports the queued spans.
 * Runs `tests/Process/exit-without-finalize.php` in a PHP subprocess, since only process exit shows it.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
final class ExitWithoutFinalizeTest
{
    public function queuedSpansAreExportedAtExit(): void
    {
        [$stdout, $stderr, $code] = self::run('ok');

        Assert::same($stdout, ['exit', 'exported: unfinished request', 'shutdown']);
        Assert::same($stderr, '');
        Assert::same($code, 0);
    }

    public function throwingShutdownAtExitIsReported(): void
    {
        [$stdout, $stderr, $code] = self::run('throw');

        Assert::same($stdout, ['exit', 'exported: unfinished request', 'shutdown', 'reported: Exporter failed']);
        Assert::same($stderr, '');
        Assert::same($code, 0);
    }

    /**
     * @return array{list<string>, string, int} Lines of stdout, stderr and the exit code.
     */
    private static function run(string $mode): array
    {
        # Files, not pipes: a child that fills the pipe nobody reads yet would block forever.
        $out = (string) \tempnam(\sys_get_temp_dir(), 'otel');
        $err = (string) \tempnam(\sys_get_temp_dir(), 'otel');

        try {
            $process = \proc_open(
                [\PHP_BINARY, __DIR__ . '/../../Process/exit-without-finalize.php', $mode],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                $pipes,
            );
            \assert(\is_resource($process));
            \fclose($pipes[0]);
            $code = \proc_close($process);

            return [\preg_split('/\R/', \trim((string) \file_get_contents($out))), (string) \file_get_contents($err), $code];
        } finally {
            @\unlink($out);
            @\unlink($err);
        }
    }
}
