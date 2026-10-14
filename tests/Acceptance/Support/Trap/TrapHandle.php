<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

/**
 * The suite's Trap server, started by the first test that needs it, so that a run selecting no
 * acceptance test starts no process.
 */
final class TrapHandle
{
    private ?TrapServer $server = null;
    private ?\Throwable $failure = null;

    /**
     * @throws \RuntimeException when Trap failed to start; every later call reports the same failure.
     */
    public function server(): TrapServer
    {
        $this->failure === null or throw new \RuntimeException($this->failure->getMessage(), previous: $this->failure);

        if ($this->server === null) {
            try {
                $this->server = TrapServer::start();
            } catch (\Throwable $e) {
                $this->failure = $e;
                throw $e;
            }
        }

        return $this->server;
    }

    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }
}
