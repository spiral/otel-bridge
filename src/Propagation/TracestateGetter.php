<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Propagation;

use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;

/**
 * W3C Trace Context requires several `tracestate` header fields to be combined into one list, in field order.
 * `TraceContextPropagator` calls `get()`, not `getAll()`, so `get()` here returns the fields joined.
 *
 * @internal
 */
final class TracestateGetter implements PropagationGetterInterface
{
    private const TRACESTATE = 'tracestate';

    private readonly PropagationGetterInterface $getter;

    public function __construct(?PropagationGetterInterface $getter = null)
    {
        $this->getter = $getter ?? ArrayAccessGetterSetter::getInstance();
    }

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

        # Not getAll(): open-telemetry/context 1.0, still allowed by the constraints, has no multi-value getter.
        $fields = [];
        foreach ($this->getter->keys($carrier) as $name) {
            if (\strcasecmp($name, self::TRACESTATE) !== 0) {
                continue;
            }

            \assert(\is_array($carrier) || $carrier instanceof \ArrayAccess);
            /** @var mixed $field */
            foreach ((array) $carrier[$name] as $field) {
                if (\is_string($field)) {
                    $fields[] = $field;
                }
            }
            break;
        }

        return $fields === [] ? null : \implode(',', $fields);
    }
}
