<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;

/**
 * Watches the shared context storage at the end of each request or job, and detaches the context that
 * registers the bridge's provider and propagator as the global ones when the app terminates.
 *
 * @internal
 */
final class ContextFinalizer
{
    use LogsMessagesTrait;

    public function __construct(
        private readonly ?ScopeInterface $registration = null,
    ) {}

    public function __invoke(bool $terminate = false): void
    {
        if ($terminate) {
            $this->registration?->detach();
            return;
        }

        # A span left active, by a hook whose post callback never ran or by a scope never detached, would
        # become the parent of every later span of the worker and hide the incoming trace context.
        $span = Span::fromContext(Context::getCurrent())->getContext();
        if ($span->isValid()) {
            self::logWarning(\sprintf(
                'A span is still active after the request or job has ended: trace %s, span %s. Every later span of the process will be its child.',
                $span->getTraceId(),
                $span->getSpanId(),
            ));
        }
    }
}
