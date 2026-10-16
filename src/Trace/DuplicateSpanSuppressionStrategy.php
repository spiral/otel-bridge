<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanKindSuppressionStrategy\SpanKindSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppression;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressor;

/**
 * Suppresses the spans in which auto-instrumentation duplicates what Spiral traces itself:
 *
 * - a span of any kind but INTERNAL inside a span of the same kind, at any depth, so the SERVER span of
 *   `open-telemetry/opentelemetry-auto-psr15` stands for the request instead of the Spiral one;
 * - every INTERNAL span of `opentelemetry-auto-psr15`, made for each request handler and middleware, as
 *   Spiral traces its middleware pipeline itself.
 *
 * A suppressed span takes the ids of its parent, so whatever is nested in it goes to the parent.
 *
 * @internal
 */
final class DuplicateSpanSuppressionStrategy implements SpanSuppressionStrategy
{
    public const PSR15_SCOPE = 'io.opentelemetry.contrib.php.psr15';

    private readonly SpanSuppressionStrategy $spanKind;

    public function __construct()
    {
        $this->spanKind = new SpanKindSuppressionStrategy();
    }

    #[\Override]
    public function getSuppressor(string $name, ?string $version, ?string $schemaUrl): SpanSuppressor
    {
        $suppressor = $this->spanKind->getSuppressor($name, $version, $schemaUrl);
        if ($name !== self::PSR15_SCOPE) {
            return $suppressor;
        }

        return new class($suppressor) implements SpanSuppressor {
            public function __construct(
                private readonly SpanSuppressor $spanKind,
            ) {}

            #[\Override]
            public function resolveSuppression(int $spanKind, array $attributes): SpanSuppression
            {
                if ($spanKind !== SpanKind::KIND_INTERNAL) {
                    return $this->spanKind->resolveSuppression($spanKind, $attributes);
                }

                return new class implements SpanSuppression {
                    #[\Override]
                    public function isSuppressed(ContextInterface $context): bool
                    {
                        return true;
                    }

                    #[\Override]
                    public function suppress(ContextInterface $context): ContextInterface
                    {
                        return $context;
                    }
                };
            }
        };
    }
}
