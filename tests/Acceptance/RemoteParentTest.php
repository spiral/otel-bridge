<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\Core\ContainerScope;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\RemoteParentHttpBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanList;
use Spiral\Queue\Interceptor\Consume\Handler;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerFactoryInterface;
use Spiral\Telemetry\TracerInterface;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * A tracer made from incoming propagation headers (W3C `traceparent` / `tracestate`): the outermost span
 * continues the remote trace, and spans below it nest under the local active span.
 *
 * Only `scoped: true` spans are activated, so only they become parents of the spans their callback starts.
 *
 * {@see ReceivedSpan::$flags} tell where a span's parent lives: a sampled span has 0x301 under a remote
 * parent and 0x101 under a local one or as a root.
 */
#[Test]
final class RemoteParentTest extends TestCase
{
    private const TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const REMOTE_SPAN_ID = 'b7ad6b7169203331';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01';
    private const FUTURE_TRACEPARENT = 'cc-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01-future-field';
    private const UNSAMPLED_TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-00';
    private const TRACESTATE = 'congo=t61rcWkgMzE,rojo=00f067aa0ba902b7';
    private const FLAGS_LOCAL_PARENT = 0x101;
    private const FLAGS_REMOTE_PARENT = 0x301;
    private const SERVER_SPAN = 'GET /ping';
    private const JOB_SPAN = 'Job handling [mail.send]';

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), RemoteParentHttpBootloader::class];
    }

    #[DataSet([['traceparent' => self::TRACEPARENT]], 'lowercase header')]
    #[DataSet([['Traceparent' => self::TRACEPARENT]], 'capitalized header')]
    #[DataSet([['TRACEPARENT' => self::TRACEPARENT]], 'uppercase header')]
    #[DataSet([['traceparent' => [self::TRACEPARENT]]], 'PSR-7 header list')]
    #[DataSet([['traceparent' => self::TRACEPARENT, 'baggage' => 'user=alice', 'x-id' => 'r1']], 'extra headers')]
    #[DataSet([['traceparent' => self::FUTURE_TRACEPARENT]], 'future version')]
    public function outermostSpanContinuesRemoteTrace(array $headers): void
    {
        $this->tracerWithContext($headers)->trace('outer', static fn() => null, scoped: true);

        $outer = $this->traces()->await(1)->named('outer');
        Assert::same($outer->traceId, self::TRACE_ID);
        Assert::same($outer->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::notSame($outer->spanId, self::REMOTE_SPAN_ID);
        Assert::same($outer->flags, self::FLAGS_REMOTE_PARENT);
    }

    #[DataSet([['traceparent' => self::TRACEPARENT]], 'lowercase header')]
    #[DataSet([['TRACEPARENT' => self::TRACEPARENT]], 'uppercase header')]
    #[DataSet([['traceparent' => [self::TRACEPARENT]]], 'PSR-7 header list')]
    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function nestedScopedSpanIsChildOfOutermostSpan(array $headers): void
    {
        $this->tracerWithContext($headers)->trace(
            'outer',
            static fn(TracerInterface $tracer) => $tracer->trace('inner', static fn() => null, scoped: true),
            scoped: true,
            traceKind: TraceKind::SERVER,
        );

        $spans = $this->traces()->await(2);
        $outer = $spans->named('outer');
        $inner = $spans->named('inner');
        Assert::count($spans, 2, "Received:\n{$spans}");
        Assert::same($outer->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($outer->kind, ReceivedSpan::KIND_SERVER);
        Assert::same($outer->flags, self::FLAGS_REMOTE_PARENT);
        Assert::same($inner->traceId, self::TRACE_ID);
        Assert::same($inner->parentSpanId, $outer->spanId);
        Assert::same($inner->kind, ReceivedSpan::KIND_INTERNAL);
        Assert::same($inner->flags, self::FLAGS_LOCAL_PARENT);
    }

    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function deeplyNestedScopedSpansFormChain(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);
        $nest = static function (int $level) use (&$nest, $tracer): void {
            $level <= 5 and $tracer->trace("level-{$level}", static fn() => $nest($level + 1), scoped: true);
        };

        $nest(1);

        $spans = $this->traces()->await(5);
        Assert::count($spans, 5, "Received:\n{$spans}");
        Assert::same($spans->named('level-1')->parentSpanId, self::REMOTE_SPAN_ID);
        for ($level = 2; $level <= 5; ++$level) {
            $span = $spans->named("level-{$level}");
            Assert::same($span->traceId, self::TRACE_ID);
            Assert::same($span->parentSpanId, $spans->named('level-' . ($level - 1))->spanId, "level-{$level}");
            Assert::same($span->flags, self::FLAGS_LOCAL_PARENT, "level-{$level}");
        }
    }

    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function siblingsShareOutermostSpanAsParent(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('outer', static function () use ($tracer): void {
            $tracer->trace('first', static fn() => null, scoped: true);
            $tracer->trace('second', static fn() => null);
            $tracer->trace('third', static fn() => null, scoped: true);
        }, scoped: true);

        $spans = $this->traces()->await(4);
        $outer = $spans->named('outer');
        Assert::count($spans, 4, "Received:\n{$spans}");
        Assert::same($outer->parentSpanId, self::REMOTE_SPAN_ID);
        foreach (['first', 'second', 'third'] as $name) {
            Assert::same($spans->named($name)->parentSpanId, $outer->spanId, $name);
            Assert::same($spans->named($name)->traceId, self::TRACE_ID, $name);
        }
    }

    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function siblingAfterThrowingChildStillNestsUnderOutermostSpan(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('outer', static function () use ($tracer): void {
            try {
                $tracer->trace('failing', static fn() => throw new \RuntimeException('Boom'), scoped: true);
            } catch (\RuntimeException) {
            }
            $tracer->trace('after', static fn() => null, scoped: true);
        }, scoped: true);

        $spans = $this->traces()->await(3);
        $outer = $spans->named('outer');
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($spans->named('failing')->parentSpanId, $outer->spanId);
        Assert::same($spans->named('after')->parentSpanId, $outer->spanId);
    }

    /**
     * A span that is not scoped is never activated, so it parents nothing: what its callback starts
     * hangs off the remote parent, as `outer` itself does.
     */
    public function spanUnderNonScopedOutermostSpanKeepsRemoteParent(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('outer', static fn() => $tracer->trace('inner', static fn() => null, scoped: true));

        $spans = $this->traces()->await(2);
        Assert::count($spans, 2, "Received:\n{$spans}");
        Assert::same($spans->named('outer')->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('inner')->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('inner')->traceId, self::TRACE_ID);
        Assert::same($spans->named('inner')->flags, self::FLAGS_REMOTE_PARENT);
    }

    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function scopedSpanUnderNonScopedOutermostSpanParentsItsOwnChildren(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('outer', static fn() => $tracer->trace(
            'inner',
            static fn() => $tracer->trace('leaf', static fn() => null),
            scoped: true,
        ));

        $spans = $this->traces()->await(3);
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($spans->named('outer')->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('inner')->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('leaf')->parentSpanId, $spans->named('inner')->spanId);
    }

    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function nonScopedMiddleSpanIsSkippedInParentChain(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('outer', static fn() => $tracer->trace(
            'middle',
            static fn() => $tracer->trace('inner', static fn() => null, scoped: true),
        ), scoped: true);

        $spans = $this->traces()->await(3);
        $outer = $spans->named('outer');
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($outer->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('middle')->parentSpanId, $outer->spanId);
        Assert::same($spans->named('inner')->parentSpanId, $outer->spanId);
    }

    /**
     * Each trace() of the tracer outside any active span is an entry span again, not a child of the
     * previous one that has already ended.
     */
    public function sequentialOutermostSpansEachTakeRemoteParent(): void
    {
        $tracer = $this->tracerWithContext(['traceparent' => self::TRACEPARENT]);

        $tracer->trace('first', static fn() => null, scoped: true);
        $tracer->trace('second', static fn() => null, scoped: true);

        $spans = $this->traces()->await(2);
        $first = $spans->named('first');
        $second = $spans->named('second');
        Assert::count($spans, 2, "Received:\n{$spans}");
        Assert::same($first->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($second->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($second->traceId, self::TRACE_ID);
        Assert::notSame($second->spanId, $first->spanId);
        Assert::same($second->flags, self::FLAGS_REMOTE_PARENT);
    }

    /**
     * A tracer made without incoming context and used inside the request span joins the request's tree:
     * the SDK takes the parent from the active context.
     */
    public function spanOfContextFreeTracerNestsUnderRemoteContinuedSpan(): void
    {
        $plain = $this->tracerWithContext([]);

        $this->tracerWithContext(['traceparent' => self::TRACEPARENT])->trace(
            'outer',
            static fn() => $plain->trace('inner', static fn() => null, scoped: true),
            scoped: true,
        );

        $spans = $this->traces()->await(2);
        Assert::same($spans->named('outer')->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('inner')->parentSpanId, $spans->named('outer')->spanId);
        Assert::same($spans->named('inner')->traceId, self::TRACE_ID);
    }

    /**
     * The container's TracerInterface is a proxy: inside a traced callback it resolves to the tracer
     * bound for that span, the one holding the incoming headers.
     */
    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function spanOfContainerTracerNestsUnderRemoteContinuedSpan(): void
    {
        $proxy = $this->tracer();

        $this->tracerWithContext(['traceparent' => self::TRACEPARENT])->trace(
            'outer',
            static fn() => $proxy->trace('inner', static fn() => null, scoped: true),
            scoped: true,
        );

        $spans = $this->traces()->await(2);
        Assert::same($spans->named('outer')->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('inner')->parentSpanId, $spans->named('outer')->spanId);
        Assert::same($spans->named('inner')->traceId, self::TRACE_ID);
    }

    public function remoteParentDoesNotLeakIntoLaterTraces(): void
    {
        $this->tracerWithContext(['traceparent' => self::TRACEPARENT])->trace(
            'request',
            static fn(TracerInterface $tracer) => $tracer->trace('inner', static fn() => null, scoped: true),
            scoped: true,
        );

        $this->tracer()->trace('later', static fn() => null, scoped: true);

        $later = $this->traces()->await(3)->named('later');
        Assert::true($later->isRoot());
        Assert::notSame($later->traceId, self::TRACE_ID);
        Assert::same($later->flags, self::FLAGS_LOCAL_PARENT);
    }

    /**
     * The SDK copies the parent's tracestate into each child span context, so every span of the request
     * carries the incoming one, whichever span is its parent.
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#shouldsample
     */
    public function incomingTracestateIsCarriedByWholeTree(): void
    {
        $this->tracerWithContext(['traceparent' => self::TRACEPARENT, 'tracestate' => self::TRACESTATE])->trace(
            'outer',
            static fn(TracerInterface $tracer) => $tracer->trace(
                'inner',
                static fn(TracerInterface $tracer) => $tracer->trace('leaf', static fn() => null, scoped: true),
                scoped: true,
            ),
            scoped: true,
        );

        $spans = $this->traces()->await(3);
        Assert::count($spans, 3, "Received:\n{$spans}");
        foreach (['outer', 'inner', 'leaf'] as $name) {
            Assert::same($spans->named($name)->traceState, self::TRACESTATE, $name);
        }
    }

    /**
     * `tracestate` alone has no parent to attach to: W3C requires ignoring it without a valid
     * `traceparent`. https://www.w3.org/TR/trace-context/#no-traceparent-received
     */
    public function tracestateWithoutTraceparentStartsNewTraceWithoutIt(): void
    {
        $this->tracerWithContext(['tracestate' => self::TRACESTATE])->trace(
            'outer',
            static fn(TracerInterface $tracer) => $tracer->trace('inner', static fn() => null, scoped: true),
            scoped: true,
        );

        $spans = $this->traces()->await(2);
        Assert::true($spans->named('outer')->isRoot());
        Assert::same($spans->named('inner')->parentSpanId, $spans->named('outer')->spanId);
        Assert::same($spans->named('outer')->traceState, '');
        Assert::same($spans->named('inner')->traceState, '');
    }

    /**
     * With the default `parentbased_always_on` sampler an unsampled remote parent makes the local root
     * unsampled, and its descendants follow their local parent: nothing of the tree is exported.
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#parentbased
     */
    public function unsampledRemoteParentExportsNoSpanOfTree(): void
    {
        $this->tracerWithContext(['traceparent' => self::UNSAMPLED_TRACEPARENT])->trace(
            'outer',
            static function (TracerInterface $tracer): void {
                $tracer->trace(
                    'inner',
                    static fn(TracerInterface $tracer) => $tracer->trace('leaf', static fn() => null, scoped: true),
                    scoped: true,
                );
                $tracer->trace('sibling', static fn() => null);
            },
            scoped: true,
        );
        $this->tracer()->trace('marker', static fn() => null);

        $spans = $this->traces()->await(1);
        Assert::count($spans, 1, "Received:\n{$spans}");
        Assert::notSame($spans->named('marker')->traceId, self::TRACE_ID);
    }

    /**
     * A sampled remote parent keeps the sampled bit on every span of the local tree.
     */
    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function sampledRemoteParentKeepsWholeTreeSampled(): void
    {
        $this->tracerWithContext(['traceparent' => self::TRACEPARENT])->trace(
            'outer',
            static fn(TracerInterface $tracer) => $tracer->trace(
                'inner',
                static fn(TracerInterface $tracer) => $tracer->trace('leaf', static fn() => null, scoped: true),
                scoped: true,
            ),
            scoped: true,
        );

        $spans = $this->traces()->await(3);
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($spans->named('outer')->flags, self::FLAGS_REMOTE_PARENT);
        Assert::same($spans->named('inner')->flags, self::FLAGS_LOCAL_PARENT);
        Assert::same($spans->named('leaf')->flags, self::FLAGS_LOCAL_PARENT);
    }

    /**
     * An invalid `traceparent` must be ignored and a new trace started, the local tree built as without
     * any incoming context. https://www.w3.org/TR/trace-context/#a-traceparent-is-received
     */
    #[DataSet(['garbage'], 'garbage')]
    #[DataSet([''], 'empty')]
    #[DataSet(['ff-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01'], 'forbidden version ff')]
    #[DataSet(['00-00000000000000000000000000000000-' . self::REMOTE_SPAN_ID . '-01'], 'all-zero trace id')]
    #[DataSet(['00-' . self::TRACE_ID . '-0000000000000000-01'], 'all-zero parent id')]
    #[DataSet(['00-0AF7651916CD43DD8448EB211C80319C-' . self::REMOTE_SPAN_ID . '-01'], 'uppercase trace id')]
    #[DataSet(['00-' . self::TRACE_ID . '-B7AD6B7169203331-01'], 'uppercase parent id')]
    #[DataSet(['00-0af7651916cd43dd8448eb211c8031-' . self::REMOTE_SPAN_ID . '-01'], 'short trace id')]
    #[DataSet(['00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID], 'missing flags')]
    #[DataSet(['00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-zz'], 'non-hex flags')]
    #[DataSet(['00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01-extra'], 'version 00 with extra field')]
    public function invalidTraceparentStartsNewTraceWithIntactTree(string $traceparent): void
    {
        $this->tracerWithContext(['traceparent' => $traceparent, 'tracestate' => self::TRACESTATE])->trace(
            'outer',
            static fn(TracerInterface $tracer) => $tracer->trace(
                'inner',
                static fn(TracerInterface $tracer) => $tracer->trace('leaf', static fn() => null, scoped: true),
                scoped: true,
            ),
            scoped: true,
        );

        $spans = $this->traces()->await(3);
        $outer = $spans->named('outer');
        $inner = $spans->named('inner');
        $leaf = $spans->named('leaf');
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::true($outer->isRoot());
        Assert::notSame(\strtolower($outer->traceId), self::TRACE_ID);
        Assert::same($outer->flags, self::FLAGS_LOCAL_PARENT);
        Assert::same($outer->traceState, '');
        Assert::same($inner->parentSpanId, $outer->spanId);
        Assert::same($leaf->parentSpanId, $inner->spanId);
        Assert::same($leaf->traceId, $outer->traceId);
    }

    #[DataSet([['traceparent' => self::TRACEPARENT]], 'lowercase header')]
    #[DataSet([['TraceParent' => self::TRACEPARENT, 'TraceState' => self::TRACESTATE]], 'mixed-case headers')]
    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function requestWithTraceparentArrivesAsTree(array $headers): void
    {
        $response = $this->fakeHttp()->get('/ping', headers: $headers);

        $spans = $this->traces()->await(6);
        $server = $spans->named(self::SERVER_SPAN);
        $handler = $spans->named('handler');
        $response->assertOk();
        Assert::count($spans, 6, "Received:\n{$spans}");
        Assert::same($server->traceId, self::TRACE_ID);
        Assert::same($server->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($handler->parentSpanId, $server->spanId);
        Assert::same($spans->named('repository')->parentSpanId, $handler->spanId);
        Assert::same($spans->named('query')->parentSpanId, $handler->spanId);
        Assert::same($spans->named('view')->parentSpanId, $handler->spanId);
        Assert::same($spans->named('template')->parentSpanId, $spans->named('view')->spanId);
        $this->assertSameTrace($spans, self::TRACE_ID);
    }

    /**
     * Control: without incoming context the same request arrives as the expected tree.
     */
    public function requestWithoutTraceparentArrivesAsTree(): void
    {
        $this->fakeHttp()->get('/ping')->assertOk();

        $spans = $this->traces()->await(6);
        $server = $spans->named(self::SERVER_SPAN);
        $handler = $spans->named('handler');
        Assert::count($spans, 6, "Received:\n{$spans}");
        Assert::true($server->isRoot());
        Assert::same($handler->parentSpanId, $server->spanId);
        Assert::same($spans->named('repository')->parentSpanId, $handler->spanId);
        Assert::same($spans->named('query')->parentSpanId, $handler->spanId);
        Assert::same($spans->named('view')->parentSpanId, $handler->spanId);
        Assert::same($spans->named('template')->parentSpanId, $spans->named('view')->spanId);
        $this->assertSameTrace($spans, $server->traceId);
    }

    public function requestWithInvalidTraceparentArrivesAsNewTree(): void
    {
        $this->fakeHttp()
            ->get('/ping', headers: ['traceparent' => '00-' . self::TRACE_ID . '-0000000000000000-01'])
            ->assertOk();

        $spans = $this->traces()->await(6);
        $server = $spans->named(self::SERVER_SPAN);
        Assert::count($spans, 6, "Received:\n{$spans}");
        Assert::true($server->isRoot());
        Assert::notSame($server->traceId, self::TRACE_ID);
        Assert::same($spans->named('handler')->parentSpanId, $server->spanId);
        Assert::same($spans->named('template')->parentSpanId, $spans->named('view')->spanId);
        $this->assertSameTrace($spans, $server->traceId);
    }

    public function requestWithUnsampledTraceparentExportsNothing(): void
    {
        $this->fakeHttp()->get('/ping', headers: ['traceparent' => self::UNSAMPLED_TRACEPARENT])
            ->assertOk()
            ->assertBodySame('pong');
        $this->tracer()->trace('marker', static fn() => null);

        $spans = $this->traces()->await(1);
        Assert::count($spans, 1, "Received:\n{$spans}");
        Assert::same($spans->spans[0]->name, 'marker');
    }

    /**
     * Each request continues its own remote trace; nothing of the first request's context reaches the second.
     */
    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function consecutiveRequestsContinueTheirOwnTraces(): void
    {
        $otherTraceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $otherSpanId = '00f067aa0ba902b7';

        $this->fakeHttp()->get('/ping', headers: ['traceparent' => self::TRACEPARENT])->assertOk();
        $this->fakeHttp()->get('/ping', headers: ['traceparent' => "00-{$otherTraceId}-{$otherSpanId}-01"])->assertOk();

        $spans = $this->traces()->await(12);
        Assert::count($spans, 12, "Received:\n{$spans}");
        foreach ([self::TRACE_ID => self::REMOTE_SPAN_ID, $otherTraceId => $otherSpanId] as $traceId => $parentId) {
            $request = new SpanList(\array_values(\array_filter(
                $spans->spans,
                static fn(ReceivedSpan $span): bool => $span->traceId === $traceId,
            )));
            Assert::count($request, 6, "Trace {$traceId}:\n{$request}");
            Assert::same($request->named(self::SERVER_SPAN)->parentSpanId, $parentId);
            Assert::same($request->named('handler')->parentSpanId, $request->named(self::SERVER_SPAN)->spanId);
            Assert::same($request->named('template')->parentSpanId, $request->named('view')->spanId);
        }
    }

    /**
     * The queue consumer traces a job as a scoped CONSUMER span made from the job headers, which arrive
     * as lists of values, like PSR-7 headers.
     */
    #[Skip('Known issue: incoming traceparent flattens the span tree, see https://github.com/spiral/otel-bridge/issues/16')]
    public function consumedJobArrivesAsTreeUnderProducerSpan(): void
    {
        $handler = new Handler(
            $this->tracingJobCore(),
            $this->getContainer()->get(TracerFactoryInterface::class),
        );

        $handler->handle('mail.send', 'sync', 'default', 'job-1', [], [
            'traceparent' => [self::TRACEPARENT],
            'tracestate' => [self::TRACESTATE],
        ]);

        $spans = $this->traces()->await(3);
        $job = $spans->named(self::JOB_SPAN);
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($job->kind, ReceivedSpan::KIND_CONSUMER);
        Assert::same($job->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($spans->named('send')->parentSpanId, $job->spanId);
        Assert::same($spans->named('render')->parentSpanId, $spans->named('send')->spanId);
        Assert::same($spans->named('render')->traceState, self::TRACESTATE);
        $this->assertSameTrace($spans, self::TRACE_ID);
    }

    /**
     * Control: a job without trace headers starts its own tree.
     */
    public function consumedJobWithoutHeadersArrivesAsTree(): void
    {
        $handler = new Handler(
            $this->tracingJobCore(),
            $this->getContainer()->get(TracerFactoryInterface::class),
        );

        $handler->handle('mail.send', 'sync', 'default', 'job-1', []);

        $spans = $this->traces()->await(3);
        $job = $spans->named(self::JOB_SPAN);
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::true($job->isRoot());
        Assert::same($spans->named('send')->parentSpanId, $job->spanId);
        Assert::same($spans->named('render')->parentSpanId, $spans->named('send')->spanId);
        $this->assertSameTrace($spans, $job->traceId);
    }

    /**
     * A job core that traces `send` → `render` through the tracer the consumer bound for the job span.
     */
    private function tracingJobCore(): HandlerInterface
    {
        return new class implements HandlerInterface {
            public function handle(CallContextInterface $context): mixed
            {
                $tracer = ContainerScope::getContainer()?->get(TracerInterface::class);
                $tracer instanceof TracerInterface or throw new \LogicException('No tracer in the job scope.');

                return $tracer->trace(
                    'send',
                    static fn() => $tracer->trace('render', static fn() => null, scoped: true),
                    scoped: true,
                );
            }
        };
    }

    private function assertSameTrace(SpanList $spans, string $traceId): void
    {
        foreach ($spans as $span) {
            Assert::same($span->traceId, $traceId, "Span {$span->name} left the trace:\n{$spans}");
        }
    }
}
