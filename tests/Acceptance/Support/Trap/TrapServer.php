<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Composer\InstalledVersions;

/**
 * A Buggregator Trap process receiving OTLP/HTTP on a random local port and dumping every frame
 * into files of its own temporary working directory.
 */
final class TrapServer
{
    private const START_ATTEMPTS = 3;

    private bool $stopped = false;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        private $process,
        private readonly array $pipes,
        public readonly int $port,
        public readonly string $workDir,
    ) {}

    /**
     * @param float $timeout Seconds to wait for the port to accept connections.
     *
     * @throws \RuntimeException when Trap does not come up.
     */
    public static function start(float $timeout = 20.0): self
    {
        $errors = [];
        for ($attempt = 1; $attempt <= self::START_ATTEMPTS; ++$attempt) {
            try {
                return self::tryStart($timeout);
            } catch (\RuntimeException $e) {
                # A free port may be taken by someone else between probing and Trap binding it.
                $errors[] = $e->getMessage();
            }
        }

        throw new \RuntimeException("Trap did not start:\n" . \implode("\n---\n", $errors));
    }

    /**
     * Base URL for `OTEL_EXPORTER_OTLP_ENDPOINT`; the exporter appends `/v1/traces`.
     */
    public function endpoint(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /**
     * Where the file sender writes `dump-*.log`: `runtime` relative to Trap's working directory.
     */
    public function dumpDirectory(): string
    {
        return $this->workDir . \DIRECTORY_SEPARATOR . 'runtime';
    }

    public function isRunning(): bool
    {
        return !$this->stopped && \proc_get_status($this->process)['running'];
    }

    /**
     * Stops the process and removes its working directory. Safe to call more than once.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        self::terminate($this->process);
        foreach ($this->pipes as $pipe) {
            \is_resource($pipe) and \fclose($pipe);
        }

        \proc_close($this->process);
        self::removeDirectory($this->workDir);
    }

    private static function tryStart(float $timeout): self
    {
        $workDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'otel-bridge-trap-' . \bin2hex(\random_bytes(6));
        \mkdir($workDir, 0777, true);
        $port = self::freePort();

        $command = [
            \PHP_BINARY,
            '-dxdebug.mode=off',
            self::binary(),
            'run',
            "--port={$port}",
            '--sender=file',
        ];
        # Output goes to files, not pipes: nobody reads Trap's output while tests run, and a full
        # pipe buffer would block the server.
        $process = \proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $workDir . \DIRECTORY_SEPARATOR . 'stdout.log', 'w'],
            2 => ['file', $workDir . \DIRECTORY_SEPARATOR . 'stderr.log', 'w'],
        ], $pipes, $workDir);
        \is_resource($process) or throw new \RuntimeException('proc_open() failed to run Trap.');

        $server = new self($process, $pipes, $port, $workDir);
        # The suite interceptor stops the server; this covers a run that dies before it gets there.
        \register_shutdown_function($server->stop(...));

        $deadline = \microtime(true) + $timeout;
        while (\microtime(true) < $deadline) {
            if (!$server->isRunning()) {
                $output = $server->output();
                $server->stop();
                throw new \RuntimeException("Trap exited on port {$port}:\n{$output}");
            }

            $socket = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket !== false) {
                \fclose($socket);
                return $server;
            }

            \usleep(100_000);
        }

        $output = $server->output();
        $server->stop();
        throw new \RuntimeException("Trap did not open port {$port} within {$timeout}s:\n{$output}");
    }

    private static function binary(): string
    {
        $path = InstalledVersions::getInstallPath('buggregator/trap');
        $path === null and throw new \RuntimeException('buggregator/trap is not installed.');

        return $path . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . 'trap';
    }

    /**
     * Lets the OS pick a free port, then releases it for Trap.
     */
    private static function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $socket === false and throw new \RuntimeException("Cannot probe a free port: {$error}");
        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }

    /**
     * @param resource $process
     */
    private static function terminate($process): void
    {
        $status = \proc_get_status($process);
        if (!$status['running']) {
            return;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            # proc_terminate() kills only the direct child; /T takes any process Trap spawned too.
            \exec(\sprintf('taskkill /F /T /PID %d 2>NUL', $status['pid']));
        } else {
            \proc_terminate($process, 15);
        }

        $deadline = \microtime(true) + 5;
        while (\proc_get_status($process)['running']) {
            if (\microtime(true) > $deadline) {
                \proc_terminate($process, 9);
                break;
            }

            \usleep(50_000);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }

        @\rmdir($directory);
    }

    private function output(): string
    {
        $output = '';
        foreach (['stdout.log', 'stderr.log'] as $file) {
            $content = @\file_get_contents($this->workDir . \DIRECTORY_SEPARATOR . $file);
            $content === false or $output .= \substr($content, -4000);
        }

        return $output;
    }
}
