<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Fixture;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Attributes\AttributeReader;
use Spiral\Attributes\ReaderInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Config\ConfiguratorInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\CompatiblePipelineBuilder;
use Spiral\Core\FactoryInterface;
use Spiral\Exceptions\ExceptionReporterInterface;
use Spiral\Exceptions\Reporter\LoggerReporter;
use Spiral\Http\Http;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Http\HttpStack;
use Spiral\Queue\Config\QueueConfig;
use Spiral\Queue\ContainerRegistry;
use Spiral\Queue\Driver\SyncDriver;
use Spiral\Queue\Failed\FailedJobHandlerInterface;
use Spiral\Queue\Failed\LogFailedJobHandler;
use Spiral\Queue\HandlerRegistryInterface;
use Spiral\Queue\Interceptor\Consume\Core as ConsumeCore;
use Spiral\Queue\Interceptor\Consume\ErrorHandlerInterceptor;
use Spiral\Queue\Interceptor\Consume\Handler;
use Spiral\Queue\Interceptor\Consume\RetryPolicyInterceptor;
use Spiral\Queue\QueueConnectionProviderInterface;
use Spiral\Queue\QueueInterface;
use Spiral\Queue\QueueManager;
use Spiral\Queue\QueueRegistry;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;

/**
 * A small app with two traced components.
 *
 * spiral/queue on the sync driver with the `mail.send` job ({@see SpiralIntegrationMailJob}), bound as
 * `QueueBootloader` binds it: that bootloader itself needs spiral/framework through the tokenizer.
 *
 * An {@see HttpStack} behind {@see SpiralIntegrationPassMiddleware} and
 * {@see SpiralIntegrationAuthMiddleware}, serving
 * - `GET /orders`: `load orders` (scoped) → `query` (scoped), answers `orders`;
 * - `POST /orders`: pushes `mail.send` to `to` of the form, answers 201 with the job id;
 * - `GET /report`: the `build report` trace of the {@see SpiralIntegrationReportService} singleton.
 */
final class SpiralIntegrationBootloader extends Bootloader
{
    public const MIDDLEWARE = [SpiralIntegrationPassMiddleware::class, SpiralIntegrationAuthMiddleware::class];
    protected const SINGLETONS = [
        Http::class => [self::class, 'initHttp'],
        SpiralIntegrationOutbox::class => SpiralIntegrationOutbox::class,
        SpiralIntegrationReportService::class => SpiralIntegrationReportService::class,
        HandlerRegistryInterface::class => QueueRegistry::class,
        QueueRegistry::class => [self::class, 'initQueueRegistry'],
        QueueConnectionProviderInterface::class => QueueManager::class,
        QueueInterface::class => [self::class, 'initQueue'],
        Handler::class => [self::class, 'initQueueHandler'],
        FailedJobHandlerInterface::class => LogFailedJobHandler::class,
        ExceptionReporterInterface::class => LoggerReporter::class,
        ReaderInterface::class => AttributeReader::class,
    ];

    public function init(ConfiguratorInterface $config): void
    {
        $config->setDefaults(QueueConfig::CONFIG, [
            'default' => 'sync',
            'connections' => ['sync' => ['driver' => 'sync']],
            'registry' => ['handlers' => [], 'serializers' => []],
            'driverAliases' => ['sync' => SyncDriver::class],
            'interceptors' => [
                'consume' => [ErrorHandlerInterceptor::class, RetryPolicyInterceptor::class],
                'push' => [],
            ],
        ]);
    }

    public function initQueueRegistry(
        ContainerInterface $container,
        FactoryInterface $factory,
        ContainerRegistry $registry,
    ): QueueRegistry {
        $queues = new QueueRegistry($container, $factory, $registry);
        $queues->setHandler('mail.send', SpiralIntegrationMailJob::class);

        return $queues;
    }

    public function initQueue(QueueConnectionProviderInterface $connections): QueueInterface
    {
        return $connections->getConnection();
    }

    public function initQueueHandler(
        ConsumeCore $core,
        QueueConfig $config,
        ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
    ): Handler {
        $interceptors = \array_map($container->get(...), $config->getConsumeInterceptors());

        return new Handler((new CompatiblePipelineBuilder())->withInterceptors(...$interceptors)->build($core), $tracerFactory);
    }

    public function initHttp(
        FactoryInterface $factory,
        #[Proxy] ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
    ): Http {
        $handler = static function (ServerRequestInterface $request) use ($container): ResponseInterface|string {
            $tracer = $container->get(TracerInterface::class);
            $responses = new Psr17Factory();

            return match ([$request->getMethod(), $request->getUri()->getPath()]) {
                ['GET', '/orders'] => $tracer->trace(
                    'load orders',
                    static fn(): string => $tracer->trace('query', static fn(): string => 'orders', scoped: true),
                    scoped: true,
                ),
                ['POST', '/orders'] => $responses->createResponse(201)->withBody($responses->createStream(
                    $container->get(QueueInterface::class)->push('mail.send', [
                        'to' => (string) ($request->getParsedBody()['to'] ?? ''),
                    ]),
                )),
                ['GET', '/report'] => $container->get(SpiralIntegrationReportService::class)->build(),
                default => $responses->createResponse(404),
            };
        };

        return HttpStack::create($factory, $container, $tracerFactory, $handler, self::MIDDLEWARE);
    }
}
