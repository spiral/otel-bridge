<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Trace;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\SpanSuppression\NoopSuppressionStrategy\NoopSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppression;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressor;

/**
 * Suppresses the spans in which `open-telemetry/opentelemetry-auto-psr15` doubles what Spiral traces itself,
 * so the tree is the same with the extension as without it:
 *
 * - its SERVER span of `Spiral\Http\Http::handle()`, as Spiral makes the SERVER span of the request;
 * - every INTERNAL span of it, made for each request handler and middleware, as Spiral traces its
 *   middleware pipeline itself.
 *
 * A suppressed span takes the context of its parent: for the SERVER span, the remote one psr15 extracts
 * from the request, which the Spiral SERVER span then gets as its parent, as it does without the extension.
 * Any other span goes to `$strategy`.
 *
 * @internal
 */
final class DuplicateSpanSuppressionStrategy implements SpanSuppressionStrategy
{
    public const PSR15_SCOPE = 'io.opentelemetry.contrib.php.psr15';
    public const HTTP_HANDLER = 'Spiral\Http\Http';

    private static ?SpanSuppression $suppressed = null;

    public function __construct(
        private readonly SpanSuppressionStrategy $strategy = new NoopSuppressionStrategy(),
    ) {}

    /**
     * The class whose method a psr15 span is made for: `code.namespace` up to psr15 1.1,
     * `code.function.name` as `Class::method` from 1.2.
     */
    public static function handlerClass(array $attributes): ?string
    {
        /** @var mixed $namespace */
        $namespace = $attributes['code.namespace'] ?? null;
        if (\is_string($namespace)) {
            return $namespace;
        }

        /** @var mixed $function */
        $function = $attributes['code.function.name'] ?? null;
        $class = \is_string($function) ? \strstr($function, '::', true) : false;

        return $class === false ? null : $class;
    }

    #[\Override]
    public function getSuppressor(string $name, ?string $version, ?string $schemaUrl): SpanSuppressor
    {
        $suppressor = $this->strategy->getSuppressor($name, $version, $schemaUrl);
        if ($name !== self::PSR15_SCOPE) {
            return $suppressor;
        }

        return new class($suppressor, self::suppressed()) implements SpanSuppressor {
            public function __construct(
                private readonly SpanSuppressor $suppressor,
                private readonly SpanSuppression $suppressed,
            ) {}

            #[\Override]
            public function resolveSuppression(int $spanKind, array $attributes): SpanSuppression
            {
                return match (true) {
                    $spanKind === SpanKind::KIND_INTERNAL,
                    $spanKind === SpanKind::KIND_SERVER
                        && DuplicateSpanSuppressionStrategy::handlerClass($attributes) === DuplicateSpanSuppressionStrategy::HTTP_HANDLER
                        => $this->suppressed,
                    default => $this->suppressor->resolveSuppression($spanKind, $attributes),
                };
            }
        };
    }

    private static function suppressed(): SpanSuppression
    {
        return self::$suppressed ??= new class implements SpanSuppression {
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
}
