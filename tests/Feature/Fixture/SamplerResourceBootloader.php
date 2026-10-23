<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature\Fixture;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use Spiral\Boot\Bootloader\Bootloader;

/**
 * Binds its own sampler and resource over the ones of the bridge, as an app would; the sampler samples
 * everything and marks each span.
 */
final class SamplerResourceBootloader extends Bootloader
{
    protected const SINGLETONS = [
        ResourceInfo::class => [self::class, 'initResource'],
        SamplerInterface::class => [self::class, 'initSampler'],
    ];

    public function initResource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create(['deployment.environment.name' => 'test']));
    }

    public function initSampler(): SamplerInterface
    {
        return new class implements SamplerInterface {
            #[\Override]
            public function shouldSample(
                ContextInterface $parentContext,
                string $traceId,
                string $spanName,
                int $spanKind,
                AttributesInterface $attributes,
                array $links,
            ): SamplingResult {
                return new SamplingResult(SamplingResult::RECORD_AND_SAMPLE, ['sampler' => 'custom']);
            }

            #[\Override]
            public function getDescription(): string
            {
                return 'custom';
            }
        };
    }
}
