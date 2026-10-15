<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit\Trace;

use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Spiral\Core\Container;
use Spiral\Exceptions\ExceptionReporterInterface;
use Spiral\OpenTelemetry\Trace\TracerProviderFinalizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The provider double is strict and throws from the one call it allows, so what gets reported tells
 * which call was made.
 */
#[Test]
#[Covers(TracerProviderFinalizer::class)]
final class TracerProviderFinalizerTest
{
    public function shutdownShutsProviderDown(): void
    {
        $failure = new \RuntimeException('shut down');
        $reported = new \ArrayObject();

        self::finalizer('shutdown', $failure, $reported)->shutdown();

        Assert::same($reported->getArrayCopy(), [$failure]);
    }

    public function failureWithoutReporterIsLogged(): void
    {
        $provider = Double::for(TracerProviderInterface::class)->strict();
        $provider->allows('forceFlush')->with(Argument::none())->throws(new \RuntimeException('Exporter failed'));
        $log = new class implements LogWriterInterface {
            /** @var list<string> */
            public array $entries = [];

            #[\Override]
            public function write($level, string $message, array $context): void
            {
                $exception = $context['exception'] ?? null;
                $this->entries[] = "{$level}: {$message}" . ($exception instanceof \Throwable ? " [{$exception->getMessage()}]" : '');
            }
        };
        Logging::setLogWriter($log);

        try {
            (new TracerProviderFinalizer($provider, new Container()))(false);
        } finally {
            Logging::reset();
        }

        Assert::count($log->entries, 1);
        Assert::string($log->entries[0])->contains('Unable to flush spans; the exception reporter failed too:');
        Assert::string($log->entries[0])->contains('[Exporter failed]');
    }

    /**
     * @param \ArrayObject<int, \Throwable> $reported
     */
    private static function finalizer(string $call, \Throwable $failure, \ArrayObject $reported): TracerProviderFinalizer
    {
        $provider = Double::for(TracerProviderInterface::class)->strict();
        $provider->allows($call)->with(Argument::none())->throws($failure);
        $container = new Container();
        $container->bindSingleton(ExceptionReporterInterface::class, new class($reported) implements ExceptionReporterInterface {
            /**
             * @param \ArrayObject<int, \Throwable> $reported
             */
            public function __construct(
                private readonly \ArrayObject $reported,
            ) {}

            #[\Override]
            public function report(\Throwable $exception): void
            {
                $this->reported[] = $exception;
            }
        });

        return new TracerProviderFinalizer($provider, $container);
    }
}
