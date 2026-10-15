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
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/../../Process/exit-without-finalize.php', $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \assert(\is_resource($process));
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);

        return [\preg_split('/\R/', \trim($stdout)), $stderr, $code];
    }
}
