<?php

/**
 * Serves one HTTP request with a Spiral app, the way a RoadRunner worker does: the request, then
 * finalize(false). The handler calls a PSR-18 client inside a scoped `handler` trace. Then the app
 * terminates, a span is ended with the global tracer provider, and the process exits.
 *
 * The OTEL_* env of the process configures the SDK. `SCENARIO_TRACEPARENT` is the incoming `traceparent`.
 * Written to stdout: `response: <traceparent of the response>`, `flushed` and `terminated`.
 */

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Process;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Globals;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\Bootloader\ConfigurationBootloader;
use Spiral\Boot\Environment;
use Spiral\Boot\FinalizerInterface;
use Spiral\Core\Attribute\Proxy;
use Spiral\Core\FactoryInterface;
use Spiral\Core\Scope;
use Spiral\Http\Http;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Http\HttpStack;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;
use Spiral\Testing\TestApp;

# The lowest dependencies are not free of deprecations on newer PHP; they are not what is checked here.
\error_reporting(\E_ALL & ~\E_DEPRECATED);

require __DIR__ . '/../../vendor/autoload.php';

final class ScenarioMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class ScenarioClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
}

final class ScenarioBootloader extends Bootloader
{
    protected const SINGLETONS = [
        Http::class => [self::class, 'initHttp'],
    ];

    public function initHttp(
        FactoryInterface $factory,
        #[Proxy] ContainerInterface $container,
        TracerFactoryInterface $tracerFactory,
    ): Http {
        return HttpStack::create(
            $factory,
            $container,
            $tracerFactory,
            static function () use ($container): string {
                $container->get(TracerInterface::class)->trace(
                    'handler',
                    static fn() => (new ScenarioClient())->sendRequest(new Request('GET', 'http://upstream.test/')),
                    scoped: true,
                );

                return 'pong';
            },
            [new ScenarioMiddleware()],
        );
    }
}

$app = TestApp::create(directories: ['root' => __DIR__ . '/..'], handleErrors: false)->withBootloaders([
    ConfigurationBootloader::class,
    OpenTelemetryBootloader::class,
    ScenarioBootloader::class,
])->run(new Environment(['TELEMETRY_DRIVER' => 'otel']));
\assert($app !== null);
$container = $app->getContainer();

$traceparent = \getenv('SCENARIO_TRACEPARENT');
$request = new ServerRequest('GET', 'http://localhost/ping', \is_string($traceparent) ? ['traceparent' => $traceparent] : []);
$response = $container->runScope(new Scope('http'), static fn(Http $http): ResponseInterface => $http->handle($request));
echo "response: {$response->getHeaderLine('traceparent')}\n";

$finalizer = $container->get(FinalizerInterface::class);
$finalizer->finalize(false);
echo "flushed\n";

$finalizer->finalize(true);
Globals::tracerProvider()->getTracer('scenario')->spanBuilder('after terminate')->startSpan()->end();
echo "terminated\n";
