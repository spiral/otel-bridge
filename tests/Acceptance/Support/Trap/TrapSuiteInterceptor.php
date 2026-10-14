<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Testo\Core\Context\SuiteInfo;
use Testo\Core\Context\SuiteResult;
use Testo\Pipeline\Middleware\TestSuiteRunInterceptor;

/**
 * Stops the suite's Trap server once the suite is over, whatever its outcome.
 */
final readonly class TrapSuiteInterceptor implements TestSuiteRunInterceptor
{
    public function __construct(
        private TrapHandle $trap,
    ) {}

    #[\Override]
    public function runTestSuite(SuiteInfo $info, callable $next): SuiteResult
    {
        try {
            return $next($info);
        } finally {
            $this->trap->stop();
        }
    }
}
