<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Support;

/**
 * The process env, where the OpenTelemetry SDK reads its settings, set for one call.
 */
final class TestEnv
{
    /**
     * Runs `$call` with process env values set and puts the old values back; `null` unsets a value.
     *
     * @template T
     *
     * @param array<string, string|null> $env
     * @param \Closure(): T $call
     *
     * @return T
     */
    public static function withProcessEnv(array $env, \Closure $call): mixed
    {
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = \getenv($key);
            \putenv($value === null ? $key : "{$key}={$value}");
        }

        try {
            return $call();
        } finally {
            foreach ($previous as $key => $value) {
                \putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    }
}
