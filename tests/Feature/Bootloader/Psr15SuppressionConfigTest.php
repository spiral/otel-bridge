<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Spiral\OpenTelemetry\Tests\Support\TestEnv;
use Spiral\OpenTelemetry\Trace\DuplicateSpanSuppressionStrategy;
use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `opentelemetry.suppress_psr15`: the spans of `opentelemetry-auto-psr15` that double Spiral ones, made
 * here as in {@see \Spiral\OpenTelemetry\Tests\Feature\DuplicateSpanSuppressionTest}, through the provider
 * of the app.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
final class Psr15SuppressionConfigTest extends TestCase
{
    private InMemoryExporter $exporter;

    public function psr15SpansAreSuppressedByDefault(): void
    {
        $psr15 = $this->psr15();

        $this->psr15Request($psr15);

        Assert::same($this->exported(), []);
    }

    #[Config('opentelemetry.suppress_psr15', false)]
    public function psr15SpansAreKeptWhenDisabled(): void
    {
        $psr15 = $this->psr15();

        $this->psr15Request($psr15);

        Assert::same($this->exported(), ['Middleware::process', 'GET']);
    }

    /**
     * The strategy of `OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY` does not depend on the setting.
     */
    #[Config('opentelemetry.suppress_psr15', false)]
    public function envStrategyAppliesWhenDisabled(): void
    {
        $psr15 = TestEnv::withProcessEnv(
            ['OTEL_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY' => 'spankind'],
            fn(): TracerInterface => $this->psr15(),
        );

        $this->inSpan($psr15, 'outer', SpanKind::KIND_SERVER, fn() => $this->psr15Request($psr15));

        Assert::same($this->exported(), ['Middleware::process', 'outer']);
    }

    /**
     * The SERVER span psr15 makes for `Spiral\Http\Http`, with an INTERNAL one of a middleware inside.
     */
    private function psr15Request(TracerInterface $psr15): void
    {
        $this->inSpan(
            $psr15,
            'GET',
            SpanKind::KIND_SERVER,
            fn() => $this->inSpan($psr15, 'Middleware::process', SpanKind::KIND_INTERNAL, static fn() => null),
            ['code.function.name' => DuplicateSpanSuppressionStrategy::HTTP_HANDLER . '::handle'],
        );
    }

    /**
     * @param array<non-empty-string, string> $attributes
     */
    private function inSpan(TracerInterface $tracer, string $name, int $kind, \Closure $body, array $attributes = []): void
    {
        $span = $tracer->spanBuilder($name)->setSpanKind($kind)->setAttributes($attributes)->startSpan();
        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));
        try {
            $body();
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * A tracer of the psr15 scope from the provider of the app, which is built here.
     */
    private function psr15(): TracerInterface
    {
        $this->exporter = new InMemoryExporter();
        $container = $this->getContainer();
        $container->bindSingleton(SpanProcessorInterface::class, new SimpleSpanProcessor($this->exporter));

        return $container
            ->get(TracerProviderInterface::class)
            ->getTracer(DuplicateSpanSuppressionStrategy::PSR15_SCOPE);
    }

    /**
     * @return list<string>
     */
    private function exported(): array
    {
        return \array_map(static fn(SpanDataInterface $s): string => $s->getName(), $this->exporter->getSpans());
    }
}
