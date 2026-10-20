<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Support\PhpProcess;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * A process that ends without finalize(true), as after a fatal error, still exports the queued spans.
 * Runs `tests/Process/exit-without-finalize.php` in a PHP subprocess, since only process exit shows it.
 * Mutation testing cannot reach this path: the child process loads the real src, not the mutant.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
#[Covers(TracerProviderFinalizer::class)]
final class ExitWithoutFinalizeTest
{
    public function queuedSpansAreExportedAtExit(): void
    {
        [$stdout, $stderr, $code] = PhpProcess::run('exit-without-finalize.php', args: ['ok']);

        Assert::same($stdout, ['exit', 'exported: unfinished request', 'shutdown']);
        Assert::same($stderr, '');
        Assert::same($code, 0);
    }

    public function throwingShutdownAtExitIsReported(): void
    {
        [$stdout, $stderr, $code] = PhpProcess::run('exit-without-finalize.php', args: ['throw']);

        Assert::same($stdout, ['exit', 'exported: unfinished request', 'shutdown', 'reported: Exporter failed']);
        Assert::same($stderr, '');
        Assert::same($code, 0);
    }
}
