<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit\Trace;

use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Matching\Argument;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Spiral\OpenTelemetry\Trace\DeferredSpanExporter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DeferredSpanExporter::class)]
final class DeferredSpanExporterTest
{
    private DoubleInterface&ExporterFactory $factory;
    private DeferredSpanExporter $exporter;

    public static function delegatedCalls(): iterable
    {
        yield 'shutdown' => ['shutdown', null];
        yield 'shutdown with cancellation' => ['shutdown', Double::for(CancellationInterface::class)->strict()];
        yield 'forceFlush' => ['forceFlush', null];
        yield 'forceFlush with cancellation' => ['forceFlush', Double::for(CancellationInterface::class)->strict()];
    }

    #[BeforeTest]
    public function createExporter(): void
    {
        $this->factory = Double::for(ExporterFactory::class)->strict();
        $this->exporter = new DeferredSpanExporter($this->factory);
    }

    public function exportDelegatesToCreatedExporter(): void
    {
        $future = Double::for(FutureInterface::class)->strict();
        $this->createdExporter()->allows('export')->with(['foo', 'bar'], null)->returns($future);

        $result = $this->exporter->export(['foo', 'bar']);

        Assert::same($result, $future);
    }

    public function exportPassesCancellationToCreatedExporter(): void
    {
        $cancellation = Double::for(CancellationInterface::class)->strict();
        $this->createdExporter()->allows('export')
            ->with(['foo', 'bar'], $cancellation)
            ->returns(Double::for(FutureInterface::class)->strict());

        $result = $this->exporter->export(['foo', 'bar'], $cancellation);

        Assert::instanceOf($result, FutureInterface::class);
    }

    public function fromConnectionStringIsNotSupported(): never
    {
        Expect::exception(\Exception::class)->withMessageContaining('Method is not supported.');

        DeferredSpanExporter::fromConnectionString('https://test.com', 'foo', 'bar');
    }

    #[DataProvider('delegatedCalls')]
    public function shutdownAndForceFlushDelegateToCreatedExporter(string $method, ?CancellationInterface $cancellation): void
    {
        $this->createdExporter()->allows($method)->with($cancellation)->returns(true);

        $result = $cancellation === null ? $this->exporter->$method() : $this->exporter->$method($cancellation);

        Assert::true($result);
    }

    public function exportSucceedsWithoutConfiguredExporter(): void
    {
        $this->factory->allows('create')->with(Argument::none())->returns(null);

        $exported = $this->exporter->export(['foo'])->await();

        Assert::true($exported);
    }

    public function shutdownAndForceFlushSucceedWithoutConfiguredExporter(): void
    {
        $this->factory->allows('create')->with(Argument::none())->returns(null);

        $flushed = $this->exporter->forceFlush();
        $shutDown = $this->exporter->shutdown();

        Assert::true($flushed);
        Assert::true($shutDown);
    }

    public function exportedFutureFailsWithFactoryError(): never
    {
        $this->factory->allows('create')->with(Argument::none())->throws(new \RuntimeException('Unknown exporter.'));
        $future = $this->exporter->export(['foo']);

        Expect::exception(\RuntimeException::class)->withMessage('Unknown exporter.');

        $future->await();
    }

    public function shutdownAndForceFlushFailWithoutThrowingOnFactoryError(): void
    {
        $this->factory->allows('create')->with(Argument::none())->throws(new \RuntimeException('Unknown exporter.'));

        $flushed = $this->exporter->forceFlush();
        $shutDown = $this->exporter->shutdown();

        Assert::false($flushed);
        Assert::false($shutDown);
    }

    /**
     * The exporter the factory creates on the first call that needs one.
     */
    private function createdExporter(): DoubleInterface&SpanExporterInterface
    {
        $exporter = Double::for(SpanExporterInterface::class)->strict();
        $this->factory->allows('create')->with(Argument::none())->returns($exporter);

        return $exporter;
    }
}
