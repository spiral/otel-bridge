<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Bootloader;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\SamplerResourceBootloader;
use Spiral\OpenTelemetry\Tests\Feature\TestCase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The provider the bridge builds takes the sampler and the resource from the container.
 */
#[Test]
#[Covers(OpenTelemetryBootloader::class)]
final class SamplerResourceBindingTest extends TestCase
{
    private bool $ownBindings = false;

    public function samplerAndResourceComeFromEnvByDefault(): void
    {
        $container = $this->getContainer();

        $span = $this->exportSpan();

        Assert::instanceOf($container->get(SamplerInterface::class), ParentBased::class);
        Assert::same($span->getResource(), $container->get(ResourceInfo::class));
        Assert::notBlank($span->getResource()->getAttributes()->get('service.name'));
    }

    public function bindingsOfTheAppAreUsed(): void
    {
        $span = $this->bootWithOwnBindings()->exportSpan();

        Assert::same($span->getAttributes()->get('sampler'), 'custom');
        Assert::same($span->getResource()->getAttributes()->toArray(), ['deployment.environment.name' => 'test']);
    }

    #[\Override]
    public function defineBootloaders(): array
    {
        return $this->ownBindings
            ? [...parent::defineBootloaders(), SamplerResourceBootloader::class]
            : parent::defineBootloaders();
    }

    private function bootWithOwnBindings(): self
    {
        $this->ownBindings = true;
        $this->makeApp();

        return $this;
    }

    private function exportSpan(): SpanDataInterface
    {
        $exporter = new InMemoryExporter();
        $container = $this->getContainer();
        $container->bindSingleton(SpanProcessorInterface::class, new SimpleSpanProcessor($exporter));

        $container->get(TracerInterface::class)->spanBuilder('span')->startSpan()->end();

        $spans = $exporter->getSpans();
        Assert::count($spans, 1);

        return $spans[0];
    }
}
