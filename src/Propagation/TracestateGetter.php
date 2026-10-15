<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Propagation;

use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\ExtendedPropagationGetterInterface;

/**
 * Hands several `tracestate` header fields to a propagator as one comma-separated list, in field order,
 * as W3C Trace Context requires. `TraceContextPropagator` reads `tracestate` with {@see get()},
 * which on a list of fields would see only the first one.
 *
 * Every other key, `traceparent` included, is read as the wrapped getter reads it.
 *
 * @internal
 */
final class TracestateGetter implements ExtendedPropagationGetterInterface
{
    private const TRACESTATE = 'tracestate';

    public function __construct(
        private readonly ExtendedPropagationGetterInterface $getter = new ArrayAccessGetterSetter(),
    ) {}

    #[\Override]
    public function keys(mixed $carrier): array
    {
        return $this->getter->keys($carrier);
    }

    #[\Override]
    public function get(mixed $carrier, string $key): ?string
    {
        if (\strcasecmp($key, self::TRACESTATE) !== 0) {
            return $this->getter->get($carrier, $key);
        }

        $fields = $this->getter->getAll($carrier, $key);

        return $fields === [] ? null : \implode(',', $fields);
    }

    #[\Override]
    public function getAll(mixed $carrier, string $key): array
    {
        return $this->getter->getAll($carrier, $key);
    }
}
