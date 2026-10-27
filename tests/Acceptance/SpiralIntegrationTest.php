<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\Core\Scope;
use Spiral\Core\ScopeInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\SpiralIntegrationBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\SpiralIntegrationOutbox;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\SpiralIntegrationReportService;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanList;
use Spiral\Queue\QueueInterface;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The Spiral components that trace on their own, wired as in an app: requests through `Spiral\Http\Http`
 * with a `LazyPipeline` of middleware, jobs through `spiral/queue` on the sync driver, and services that
 * get `TracerInterface` from the container. See {@see SpiralIntegrationBootloader} for the app.
 */
#[Test]
final class SpiralIntegrationTest extends TestCase
{
    private const FLAGS_LOCAL_PARENT = 0x101;
    private const PIPELINE_SPAN = 'HTTP Pipeline';
    private const PUSH_SPAN = 'Job push [mail.send]';
    private const JOB_SPAN = 'Job handling [mail.send]';

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), SpiralIntegrationBootloader::class];
    }

    /**
     * spiral/http traces the request as a SERVER span, the middleware run as one `HTTP Pipeline` span
     * listing every middleware, and the spans of middleware and handler nest under the pipeline.
     */
    public function requestThroughMiddlewareArrivesAsTree(): void
    {
        $response = $this->fakeHttp()->get('/orders');

        $spans = $this->traces()->await(5);
        $server = $spans->server();
        $pipeline = $spans->named(self::PIPELINE_SPAN);
        $load = $spans->named('load orders');
        $response->assertOk()->assertBodySame('orders');
        Assert::count($spans, 5, "Received:\n{$spans}");
        Assert::true($server->isRoot());
        # spiral/http sets Ok below 500, or leaves it Unset as the HTTP conventions say.
        Assert::contains([ReceivedSpan::STATUS_OK, ReceivedSpan::STATUS_UNSET], $server->statusCode);
        Assert::same($server->flags, self::FLAGS_LOCAL_PARENT);
        Assert::same($pipeline->parentSpanId, $server->spanId);
        Assert::same($pipeline->kind, ReceivedSpan::KIND_INTERNAL);
        Assert::same($pipeline->attributes['http.middleware'] ?? null, SpiralIntegrationBootloader::MIDDLEWARE);
        Assert::same($spans->named('authenticate')->parentSpanId, $pipeline->spanId);
        Assert::same($spans->named('authenticate')->attributes['enduser.id'] ?? null, 'alice');
        Assert::same($load->parentSpanId, $pipeline->spanId);
        Assert::same($spans->named('query')->parentSpanId, $load->spanId);
        self::assertOneTrace($spans, $server->traceId);
    }

    /**
     * The name and attributes come from spiral/http, under the keys of the HTTP conventions it follows.
     */
    #[DataSet(['/orders', [], '/orders'], 'path only')]
    #[DataSet(['/orders', ['status' => 'paid', 'page' => '2'], '/orders?status=paid&page=2'], 'query string')]
    public function serverSpanCarriesTheRequestAndResponse(string $path, array $query, string $target): void
    {
        $this->fakeHttp()->get($path, query: $query)->assertOk();

        $server = $this->traces()->await(5)->server();
        Assert::string($server->name)->startsWith('GET');
        Assert::same($server->attributes['http.request.method'] ?? null, 'GET');
        Assert::same($server->httpTarget(), $target);
        Assert::same($server->attributes['http.response.status_code'] ?? null, 200);
        Assert::same($server->attributes['http.response.body.size'] ?? null, 6);
    }

    /**
     * The handler pushes a job on the sync driver: the push, the job and what the job traces all land
     * in the trace of the request.
     */
    public function jobPushedByRequestHandlerStaysInTraceOfRequest(): void
    {
        $response = $this->fakeHttp()->post('/orders', ['to' => 'carol@example.com']);

        $spans = $this->traces()->await(6);
        $server = $spans->server();
        $job = $spans->named(self::JOB_SPAN);
        $jobId = (string) $response->getOriginalResponse()->getBody();
        $response->assertStatus(201);
        Assert::same($this->getContainer()->get(SpiralIntegrationOutbox::class)->delivered, ['carol@example.com']);
        Assert::count($spans, 6, "Received:\n{$spans}");
        Assert::same($server->attributes['http.request.method'] ?? null, 'POST');
        Assert::same($server->httpTarget(), '/orders');
        Assert::same($server->attributes['http.response.status_code'] ?? null, 201);
        Assert::same($server->attributes['http.response.body.size'] ?? null, \strlen($jobId));
        Assert::contains([ReceivedSpan::STATUS_OK, ReceivedSpan::STATUS_UNSET], $server->statusCode);
        Assert::same($spans->named(self::PUSH_SPAN)->parentSpanId, $spans->named(self::PIPELINE_SPAN)->spanId);
        Assert::same($job->kind, ReceivedSpan::KIND_CONSUMER);
        Assert::same($job->attributes['queue.job.id'] ?? null, $jobId);
        Assert::same($spans->named('deliver')->parentSpanId, $job->spanId);
        self::assertOneTrace($spans, $server->traceId);
    }

    /**
     * The job has to continue the span running when it is pushed, the `HTTP Pipeline`; the job headers
     * carry a span the tracer started and ended before it.
     */
    public function jobPushedByRequestHandlerContinuesSpanRunningAtPush(): void
    {
        $this->fakeHttp()->post('/orders', ['to' => 'carol@example.com'])->assertStatus(201);

        $spans = $this->traces()->await(6);
        Assert::same($spans->named(self::JOB_SPAN)->parentSpanId, $spans->named(self::PIPELINE_SPAN)->spanId);
    }

    public function consecutiveRequestsArriveAsIndependentTraces(): void
    {
        $this->fakeHttp()->get('/orders')->assertOk();
        $this->fakeHttp()->get('/orders')->assertOk();

        $spans = $this->traces()->await(10);
        Assert::count($spans, 10, "Received:\n{$spans}");
        $traces = [];
        foreach ($spans as $span) {
            $traces[$span->traceId][] = $span;
        }
        Assert::count($traces, 2, "Received:\n{$spans}");
        foreach ($traces as $traceId => $trace) {
            $request = new SpanList($trace);
            Assert::count($request, 5, "Trace {$traceId}:\n{$request}");
            Assert::true($request->server()->isRoot());
            Assert::same($request->named(self::PIPELINE_SPAN)->parentSpanId, $request->server()->spanId);
            Assert::same($request->named('query')->parentSpanId, $request->named('load orders')->spanId);
        }
    }

    /**
     * The response carries the trace of the request, sampled.
     * https://www.w3.org/TR/trace-context/#traceparent-header-field-values
     */
    public function responseTraceparentCarriesTraceOfRequest(): void
    {
        $response = $this->fakeHttp()->get('/orders');

        $server = $this->traces()->await(5)->server();
        Assert::string($response->getOriginalResponse()->getHeaderLine('traceparent'))
            ->matchesRegex("/^00-{$server->traceId}-[0-9a-f]{16}-01\$/");
    }

    /**
     * The next hop has to continue the SERVER span; with middleware the tracer has started the
     * `HTTP Pipeline` span after it, and that is what the response carries.
     */
    public function responseTraceparentBehindMiddlewarePointsToServerSpan(): void
    {
        $response = $this->fakeHttp()->get('/orders');

        $server = $this->traces()->await(5)->server();
        Assert::same(
            $response->getOriginalResponse()->getHeaderLine('traceparent'),
            "00-{$server->traceId}-{$server->spanId}-01",
        );
    }

    /**
     * `TracerInterface` is bound as a proxy: a singleton built before any request still traces under the
     * span running when it is called.
     */
    public function singletonBuiltBeforeRequestTracesUnderRequest(): void
    {
        $this->getContainer()->get(SpiralIntegrationReportService::class);

        $this->fakeHttp()->get('/report')->assertOk()->assertBodySame('report');

        $spans = $this->traces()->await(4);
        Assert::count($spans, 4, "Received:\n{$spans}");
        Assert::same($spans->named('build report')->parentSpanId, $spans->named(self::PIPELINE_SPAN)->spanId);
        self::assertOneTrace($spans, $spans->server()->traceId);
    }

    public function serviceResolvedInChildScopeTracesUnderActiveSpan(): void
    {
        $this->tracer()->trace(
            'outer',
            static fn(ScopeInterface $scope): string => $scope->runScope(
                new Scope('reporting'),
                static fn(SpiralIntegrationReportService $report): string => $report->build(),
            ),
            scoped: true,
        );

        $spans = $this->traces()->await(2);
        Assert::count($spans, 2, "Received:\n{$spans}");
        Assert::true($spans->named('outer')->isRoot());
        Assert::same($spans->named('build report')->parentSpanId, $spans->named('outer')->spanId);
        Assert::same($spans->named('build report')->traceId, $spans->named('outer')->traceId);
    }

    public function serviceOutsideAnySpanStartsItsOwnTrace(): void
    {
        $report = $this->getContainer()->get(SpiralIntegrationReportService::class);

        $report->build();
        $report->build();

        $spans = $this->traces()->await(2);
        Assert::count($spans, 2, "Received:\n{$spans}");
        Assert::true($spans->spans[0]->isRoot());
        Assert::true($spans->spans[1]->isRoot());
        Assert::notSame($spans->spans[0]->traceId, $spans->spans[1]->traceId);
    }

    /**
     * spiral/queue takes the job headers from the tracer before it starts its push span, so the job
     * continues the span that pushed it, next to the push span; a span the job handler starts nests
     * under the CONSUMER span. The sync driver runs the job while `checkout` is still active, so `checkout`
     * is its local parent rather than a remote one from the job headers.
     *
     * @todo Question: messaging conventions have the consumer continue the producer's own span, of kind
     *       PRODUCER (https://opentelemetry.io/docs/specs/semconv/messaging/messaging-spans/); spiral/queue
     *       makes the push span INTERNAL and does not propagate it. Lock this in, or treat it as an issue?
     */
    public function pushedJobContinuesTraceOfPushingSpan(): void
    {
        $queue = $this->getContainer()->get(QueueInterface::class);

        $this->tracer()->trace(
            'checkout',
            static fn() => $queue->push('mail.send', ['to' => 'alice@example.com']),
            scoped: true,
        );

        $spans = $this->traces()->await(4);
        $checkout = $spans->named('checkout');
        $push = $spans->named(self::PUSH_SPAN);
        $job = $spans->named(self::JOB_SPAN);
        $deliver = $spans->named('deliver');
        Assert::same($this->getContainer()->get(SpiralIntegrationOutbox::class)->delivered, ['alice@example.com']);
        Assert::count($spans, 4, "Received:\n{$spans}");
        Assert::true($checkout->isRoot());
        Assert::same($push->parentSpanId, $checkout->spanId);
        Assert::same($push->kind, ReceivedSpan::KIND_INTERNAL);
        Assert::same($job->parentSpanId, $checkout->spanId);
        Assert::same($job->kind, ReceivedSpan::KIND_CONSUMER);
        Assert::same($job->flags, self::FLAGS_LOCAL_PARENT);
        Assert::same($deliver->parentSpanId, $job->spanId);
        Assert::same($deliver->flags, self::FLAGS_LOCAL_PARENT);
        self::assertOneTrace($spans, $checkout->traceId);
    }

    public function jobSpansCarryQueueAttributes(): void
    {
        $queue = $this->getContainer()->get(QueueInterface::class);

        $id = $this->tracer()->trace(
            'checkout',
            static fn(): string => $queue->push('mail.send', ['to' => 'bob@example.com']),
            scoped: true,
        );

        $spans = $this->traces()->await(4);
        $job = $spans->named(self::JOB_SPAN);
        Assert::same($spans->named(self::PUSH_SPAN)->attributes, ['queue.handler' => 'mail.send']);
        Assert::same($job->attributes['queue.driver'] ?? null, 'sync');
        Assert::same($job->attributes['queue.name'] ?? null, 'default');
        Assert::same($job->attributes['queue.job.name'] ?? null, 'mail.send');
        Assert::same($job->attributes['queue.job.id'] ?? null, $id);
    }

    private static function assertOneTrace(SpanList $spans, string $traceId): void
    {
        foreach ($spans as $span) {
            Assert::same($span->traceId, $traceId, "Span {$span->name} left the trace:\n{$spans}");
        }
    }
}
