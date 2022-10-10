<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Queue;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\CoreInterceptorInterface;
use Spiral\Core\CoreInterface;
use Spiral\RoadRunner\Jobs\Task\WritableHeadersInterface;
use Spiral\RoadRunnerBridge\Queue\Options;

class OpenTelemetryConsumeInterceptor implements CoreInterceptorInterface
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator
    ) {
    }

    public function process(string $controller, string $action, array $parameters, CoreInterface $core): mixed
    {


        return $core->callAction($controller, $action, $parameters);
    }
}
