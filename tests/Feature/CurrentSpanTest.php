<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Feature;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Span\ActiveTrace;
use Spiral\OpenTelemetry\Span\OtelCurrentSpanProvider;
use Spiral\OpenTelemetry\Span\OtelSpanAdapter;
use Spiral\OpenTelemetry\Span\SpanConverter;
use Spiral\OpenTelemetry\Tests\Feature\Fixture\AttributePriority;
use Spiral\OpenTelemetry\Tracer;
use Spiral\Telemetry\CurrentSpan;
use Spiral\Telemetry\Span\NoopSpan;
use Spiral\Telemetry\SpanInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OtelCurrentSpanProvider::class)]
#[Covers(ActiveTrace::class)]
#[Covers(OtelSpanAdapter::class)]
#[Covers(SpanConverter::class)]
#[Covers(Tracer::class)]
final class CurrentSpanTest
{
    private InMemoryExporter $exporter;
    private Tracer $tracer;
    private TracerProvider $provider;

    #[BeforeTest]
    public function createTracer(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracer = new Tracer(
            new Container(),
            ($this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter)))->getTracer('test'),
            TraceContextPropagator::getInstance(),
        );
        # As the bootloader does: outside a Spiral trace, only the provider knows the span.
        CurrentSpan::setProvider(new OtelCurrentSpanProvider());
    }

    #[AfterTest]
    public function unsetProvider(): void
    {
        CurrentSpan::setProvider(null);
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isTheSpanOfTheTrace(bool $scoped): void
    {
        $this->tracer->trace('test', static function (SpanInterface $span): void {
            Assert::same(CurrentSpan::get(), $span);
            CurrentSpan::get()->setAttribute('count', 3);
        }, scoped: $scoped);

        Assert::same($this->span('test')->getAttributes()->toArray(), ['count' => 3]);
    }

    public function attributesAreNormalized(): void
    {
        $this->tracer->trace('test', static fn() => CurrentSpan::get()->setAttribute('priority', AttributePriority::High));

        Assert::same($this->span('test')->getAttributes()->toArray(), ['priority' => AttributePriority::High->value]);
    }

    public function nestedTraceShadowsOuterUntilItEnds(): void
    {
        $this->tracer->trace('outer', function (): void {
            $this->tracer->trace('inner', static fn() => CurrentSpan::get()->setAttribute('in', 'inner'));
            CurrentSpan::get()->setAttribute('in', 'outer');
        });

        Assert::same($this->span('inner')->getAttributes()->toArray(), ['in' => 'inner']);
        Assert::same($this->span('outer')->getAttributes()->toArray(), ['in' => 'outer']);
    }

    public function isRestoredWhenTraceThrows(): void
    {
        try {
            $this->tracer->trace('test', static fn() => throw new \RuntimeException('Broken'));
        } catch (\RuntimeException) {
        }

        Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
    }

    public function isNoopOutsideTrace(): void
    {
        $span = CurrentSpan::get();
        $span->setAttribute('count', 3)->setAttributes(['a' => 1])->setStatus('error')->updateName('renamed');

        Assert::instanceOf($span, NoopSpan::class);
        Assert::same($span->getName(), 'noop');
        Assert::same($span->getAttributes(), []);
        Assert::false($span->hasAttribute('count'));
        Assert::null($span->getAttribute('count'));
        Assert::null($span->getStatus());
        Assert::same($this->exporter->getSpans(), []);
    }

    public function traceNotScopedLeavesOtelSpanInactive(): void
    {
        $this->tracer->trace('test', static fn() => Assert::false(OtelSpan::getCurrent()->getContext()->isValid()));
    }

    public function fiberSeesItsOwnTrace(): void
    {
        $fiber = new \Fiber(function (): void {
            # Unless OTEL_PHP_FIBERS_ENABLED forks the context over FFI, a fiber has none until one is attached.
            $scope = Context::getRoot()->activate();
            $this->tracer->trace('fiber', static function (): void {
                \Fiber::suspend();
                CurrentSpan::get()->setAttribute('in', 'fiber');
            });
            $scope->detach();
        });

        $this->tracer->trace('main', static function () use ($fiber): void {
            $fiber->start();
            CurrentSpan::get()->setAttribute('in', 'main');
            $fiber->resume();
        });

        Assert::same($this->span('fiber')->getAttributes()->toArray(), ['in' => 'fiber']);
        Assert::same($this->span('main')->getAttributes()->toArray(), ['in' => 'main']);
    }

    /**
     * The framework hands the trace to a fiber started inside it, but OTel does not hand the context.
     */
    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function fiberStartedInsideTraceWithContextAttachedSeesTheTrace(bool $scoped): void
    {
        $this->tracer->trace('trace', static function (SpanInterface $trace): void {
            $context = Context::getCurrent();
            $fiber = new \Fiber(static function () use ($context, $trace): void {
                $scope = $context->activate();
                try {
                    Assert::same(CurrentSpan::get(), $trace);
                    \Fiber::suspend();
                    Assert::same(CurrentSpan::get(), $trace);
                    CurrentSpan::get()->setAttribute('in', 'fiber');
                } finally {
                    $scope->detach();
                }
            });
            $fiber->start();
            Assert::same(CurrentSpan::get(), $trace);
            $fiber->resume();
        }, scoped: $scoped);

        Assert::same($this->span('trace')->getAttributes()->toArray(), ['in' => 'fiber']);
    }

    /**
     * Without the context attached, reading it in the fiber raises an OTel warning, which the framework
     * takes for no answer from the tracer: the span of the trace it handed to the fiber.
     */
    public function fiberStartedInsideTraceWithoutContextSeesTheTrace(): void
    {
        \set_error_handler(static fn(int $code, string $message): never => throw new \ErrorException($message, 0, $code));
        try {
            $this->tracer->trace('trace', static function (SpanInterface $trace): void {
                $fiber = new \Fiber(static fn() => Assert::same(CurrentSpan::get(), $trace));
                $fiber->start();
            });
        } finally {
            \restore_error_handler();
        }
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isSpanActivatedInsideTraceUntilItIsDetached(bool $scoped): void
    {
        $this->tracer->trace('trace', function (): void {
            $this->inSpan('inner', static fn() => CurrentSpan::get()->setAttribute('in', 'inner'));
            CurrentSpan::get()->setAttribute('after', 'trace');
        }, scoped: $scoped);

        Assert::same($this->span('inner')->getAttributes()->toArray(), ['in' => 'inner']);
        Assert::same($this->span('trace')->getAttributes()->toArray(), ['after' => 'trace']);
    }

    public function isSpanActiveOutsideTrace(): void
    {
        $this->inSpan('outer', static fn() => CurrentSpan::get()->setAttribute('in', 'outer'));

        Assert::same($this->span('outer')->getAttributes()->toArray(), ['in' => 'outer']);
    }

    public function activeSpanGetsConvertedValuesAtOnce(): void
    {
        $this->inSpan('test', static function (): void {
            $span = CurrentSpan::get()
                ->updateName('renamed')
                ->setAttribute('priority', AttributePriority::High)
                ->setAttributes(['list' => [1, null], 'map' => ['a' => 1]]);

            Assert::instanceOf($span, OtelSpanAdapter::class);
            Assert::same($span->getName(), 'renamed');
            Assert::same($span->getAttribute('priority'), AttributePriority::High->value);
            Assert::true($span->hasAttribute('priority'));
            Assert::false($span->hasAttribute('missing'));
            Assert::same($span->getAttributes(), ['priority' => 42, 'list' => [1, 0], 'map' => '{"a":1}']);
            Assert::null($span->getStatus());
        });

        Assert::same($this->span('renamed')->getAttributes()->count(), 3);
    }

    #[DataSet([SpanKind::KIND_SERVER, 404, null], '404 on server')]
    #[DataSet([SpanKind::KIND_INTERNAL, 404, StatusCode::STATUS_ERROR], '404 on internal')]
    #[DataSet([SpanKind::KIND_SERVER, 'ok', StatusCode::STATUS_OK], 'ok')]
    public function activeSpanStatusIsConvertedByKind(int $kind, int|string $code, ?string $expected): void
    {
        $this->inSpan('test', static function () use ($code, $expected): void {
            $span = CurrentSpan::get();
            $status = $span->setStatus($code, 'reason')->getStatus();

            Assert::instanceOf($span, OtelSpanAdapter::class);
            Assert::same($status?->code, $expected);
            Assert::same($status?->description, $expected === StatusCode::STATUS_ERROR ? 'reason' : null);
        }, $kind);
    }

    #[DataSet([false, false], 'not scoped')]
    #[DataSet([true, false], 'scoped')]
    #[DataSet([false, true], 'not scoped, remote')]
    #[DataSet([true, true], 'scoped, remote')]
    public function isNoopInsideNonRecordingSpan(bool $scoped, bool $remote): void
    {
        $this->tracer->trace('trace', static function () use ($remote): void {
            $context = $remote
                ? SpanContext::createFromRemoteParent('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', TraceFlags::SAMPLED)
                : SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331');
            $scope = OtelSpan::wrap($context)->activate();
            Assert::instanceOf(CurrentSpan::get()->setAttribute('in', 'unsampled'), NoopSpan::class);
            $scope->detach();
        }, scoped: $scoped);

        Assert::same($this->span('trace')->getAttributes()->toArray(), []);
    }

    public function isNoopInsideEndedSpan(): void
    {
        $span = $this->provider->getTracer('test')->spanBuilder('ended')->startSpan();
        $span->end();
        $scope = $span->activate();

        try {
            Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
        } finally {
            $scope->detach();
        }
    }

    /**
     * A suppressed span, as of the psr15 hook on a middleware Spiral traces, is a non-recording wrapper of
     * the active span's context.
     */
    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isTraceInsideSuppressedSpan(bool $scoped): void
    {
        # Under a span, so that a trace not scoped also has a span to wrap: the trace is not its own parent.
        $this->inSpan('enclosing', fn() => $this->tracer->trace('trace', static function (): void {
            $scope = OtelSpan::wrap(OtelSpan::getCurrent()->getContext())->activate();
            CurrentSpan::get()->setAttribute('in', 'suppressed');
            $scope->detach();
        }, scoped: $scoped));

        Assert::same($this->span('trace')->getAttributes()->toArray(), ['in' => 'suppressed']);
        Assert::same($this->span('enclosing')->getAttributes()->toArray(), []);
    }

    /**
     * The span that stays active in a trace not scoped is the enclosing one, which must not take its place.
     */
    public function traceNotScopedInsideSpanIsTheTrace(): void
    {
        $this->inSpan('enclosing', fn() => $this->tracer->trace(
            'trace',
            static fn() => CurrentSpan::get()->setAttribute('in', 'trace'),
        ));

        Assert::same($this->span('trace')->getAttributes()->toArray(), ['in' => 'trace']);
        Assert::same($this->span('enclosing')->getAttributes()->toArray(), []);
    }

    public function traceEndsAllTheSameWhenSpanProcessorThrows(): void
    {
        $processor = new class implements SpanProcessorInterface {
            public bool $throw = true;

            public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

            public function onEnd(ReadableSpanInterface $span): void
            {
                $this->throw and throw new \RuntimeException('Broken processor');
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
        $tracer = new Tracer(
            new Container(),
            (new TracerProvider([$processor, new SimpleSpanProcessor($this->exporter)]))->getTracer('test'),
            TraceContextPropagator::getInstance(),
        );

        try {
            $tracer->trace('broken', static fn() => null, scoped: true);
        } catch (\RuntimeException) {
        }
        $processor->throw = false;

        Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
        Assert::false(OtelSpan::getCurrent()->getContext()->isValid());
        $tracer->trace('next', static fn() => null);
        Assert::string($tracer->getContext()['traceparent'] ?? '')->contains($this->span('next')->getTraceId());
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isNoopInContextActivatedAfterTraceEnded(bool $scoped): void
    {
        $context = $this->tracer->trace('trace', static fn(): ContextInterface => Context::getCurrent(), scoped: $scoped);
        $scope = $context->activate();

        try {
            Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
        } finally {
            $scope->detach();
        }
    }

    #[DataSet([false, false], 'neither scoped')]
    #[DataSet([false, true], 'nested scoped')]
    #[DataSet([true, false], 'outer scoped')]
    #[DataSet([true, true], 'both scoped')]
    public function isOuterTraceInContextOfEndedNestedTrace(bool $outerScoped, bool $nestedScoped): void
    {
        $this->tracer->trace('outer', function (SpanInterface $outer) use ($nestedScoped): void {
            $context = $this->tracer->trace('nested', static fn(): ContextInterface => Context::getCurrent(), scoped: $nestedScoped);
            $scope = $context->activate();
            # The Spiral span, not an OTel span of the outer trace, which the trace's end would overwrite.
            Assert::same(CurrentSpan::get(), $outer);
            CurrentSpan::get()->setAttribute('in', 'outer');
            $scope->detach();
        }, scoped: $outerScoped);

        Assert::same($this->span('outer')->getAttributes()->toArray(), ['in' => 'outer']);
        Assert::same($this->span('nested')->getAttributes()->toArray(), []);
    }

    /**
     * As in a callback that outlives a `#[WithSpan]` method of the trace, alone or with a trace nested in it.
     */
    #[DataSet([false, false], 'not scoped')]
    #[DataSet([true, false], 'scoped')]
    #[DataSet([false, true], 'not scoped, nested trace')]
    #[DataSet([true, true], 'scoped, nested trace')]
    public function isTraceInContextOfEndedSpanActivatedInIt(bool $scoped, bool $nested): void
    {
        $this->tracer->trace('trace', function (SpanInterface $trace) use ($nested): void {
            $context = null;
            $this->inSpan('ended', function () use (&$context, $nested): void {
                $context = $nested
                    ? $this->tracer->trace('nested', static fn(): ContextInterface => Context::getCurrent())
                    : Context::getCurrent();
            });
            $scope = $context->activate();
            try {
                Assert::same(CurrentSpan::get(), $trace);
            } finally {
                $scope->detach();
            }
        }, scoped: $scoped);
    }

    /**
     * With debug scopes off, as in production, a nested trace that leaves a scope attached ends without a
     * notice, and the context it leaves stays current for the rest of the outer trace.
     */
    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isOuterTraceAfterNestedTraceLeftScopeAttached(bool $scoped): void
    {
        $assertions = (string) \ini_get('zend.assertions');
        # -1, as in production, can only be changed in php.ini, and leaves debug scopes off already.
        $assertions === '1' and \ini_set('zend.assertions', '0');
        $leaked = null;
        try {
            $this->tracer->trace('outer', function () use (&$leaked, $scoped): void {
                $this->tracer->trace('nested', static function () use (&$leaked): void {
                    $leaked = Context::getCurrent()->activate();
                }, scoped: $scoped);
                CurrentSpan::get()->setAttribute('in', 'outer');
            }, scoped: $scoped);
        } finally {
            $leaked?->detach();
            $assertions === '1' and \ini_set('zend.assertions', $assertions);
        }

        Assert::same($this->span('outer')->getAttributes()->toArray(), ['in' => 'outer']);
    }

    /**
     * With debug scopes on, detaching the trace's scope under a scope the callback left attached raises a
     * notice, which the error handler of a Spiral app turns into an exception.
     */
    public function traceLeavingScopeAttachedEndsAllTheSame(): void
    {
        $leaked = null;
        \set_error_handler(static fn(int $code, string $message): never => throw new \ErrorException($message, 0, $code));
        try {
            $this->tracer->trace('leaking', static function () use (&$leaked): void {
                $leaked = Context::getCurrent()->activate();
            });
        } catch (\ErrorException) {
        } finally {
            \restore_error_handler();
        }

        try {
            Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
            $this->tracer->trace('next', static fn() => null);
            Assert::string($this->tracer->getContext()['traceparent'] ?? '')
                ->contains($this->span('next')->getTraceId());
        } finally {
            $leaked?->detach();
        }
    }

    /**
     * @param list<int|string> $codes
     */
    #[DataSet([[500, 200], StatusCode::STATUS_ERROR], 'unset ignored')]
    #[DataSet([['ok', 'error'], StatusCode::STATUS_OK], 'ok final')]
    #[DataSet([['error', 'ok'], StatusCode::STATUS_OK], 'error then ok')]
    #[DataSet([[2], StatusCode::STATUS_ERROR], 'no description')]
    public function activeSpanStatusFollowsSdkRules(array $codes, string $expected): void
    {
        $this->inSpan('test', static function () use ($codes, $expected): void {
            $span = CurrentSpan::get();
            foreach ($codes as $code) {
                $span->setStatus($code);
            }

            Assert::same($span->getStatus()?->code, $expected);
            Assert::null($span->getStatus()?->description);
        });

        Assert::same($this->span('test')->getStatus()->getCode(), $expected);
    }

    public function activeSpanAttributeSetToNullIsRemoved(): void
    {
        $this->inSpan('test', static function (): void {
            $span = CurrentSpan::get()->setAttribute('count', 3)->setAttribute('count', null);

            Assert::false($span->hasAttribute('count'));
            Assert::same($span->getAttributes(), []);
        });

        Assert::same($this->span('test')->getAttributes()->toArray(), []);
    }

    public function isInnermostTraceOfAnyTracer(): void
    {
        $other = new Tracer(
            new Container(),
            $this->provider->getTracer('other'),
            TraceContextPropagator::getInstance(),
        );

        $this->tracer->trace('outer', static function (SpanInterface $outer) use ($other): void {
            $other->trace('inner', static fn(SpanInterface $inner) => Assert::same(CurrentSpan::get(), $inner));
            Assert::same(CurrentSpan::get(), $outer);
        });
    }

    public function isNoopInsideSpanSuppressedInSpanActivatedInsideTrace(): void
    {
        $this->tracer->trace('trace', function (): void {
            $this->inSpan('inner', static function (): void {
                $scope = OtelSpan::wrap(OtelSpan::getCurrent()->getContext())->activate();
                try {
                    Assert::instanceOf(CurrentSpan::get()->setAttribute('in', 'suppressed'), NoopSpan::class);
                } finally {
                    $scope->detach();
                }
            });
        }, scoped: true);

        Assert::same($this->span('trace')->getAttributes()->toArray(), []);
        Assert::same($this->span('inner')->getAttributes()->toArray(), []);
    }

    #[DataSet([false], 'not scoped')]
    #[DataSet([true], 'scoped')]
    public function isNoopInContextOfEndedGrandchildSpanOfTrace(bool $scoped): void
    {
        $this->tracer->trace('trace', function () use ($scoped): void {
            $context = null;
            $this->inSpan('child', function () use (&$context): void {
                $this->inSpan('grandchild', static function () use (&$context): void {
                    $context = Context::getCurrent();
                });
            });
            $scope = $context->activate();
            try {
                Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
            } finally {
                $scope->detach();
            }
        }, scoped: $scoped);
    }

    #[DataSet([false, false], 'not scoped')]
    #[DataSet([true, false], 'scoped')]
    #[DataSet([false, true], 'not scoped, ended span in it')]
    #[DataSet([true, true], 'scoped, ended span in it')]
    public function isRunningSpanInContextOfTraceEndedInIt(bool $scoped, bool $withEndedSpan): void
    {
        $this->inSpan('enclosing', function () use ($scoped, $withEndedSpan): void {
            $context = $this->tracer->trace('trace', function () use ($withEndedSpan): ContextInterface {
                if (!$withEndedSpan) {
                    return Context::getCurrent();
                }
                $context = null;
                $this->inSpan('ended', static function () use (&$context): void {
                    $context = Context::getCurrent();
                });
                return $context;
            }, scoped: $scoped);
            $scope = $context->activate();
            try {
                Assert::instanceOf(CurrentSpan::get()->setAttribute('in', 'enclosing'), OtelSpanAdapter::class);
            } finally {
                $scope->detach();
            }
        });

        Assert::same($this->span('enclosing')->getAttributes()->toArray(), ['in' => 'enclosing']);
        Assert::same($this->span('trace')->getAttributes()->toArray(), []);
    }

    /**
     * The invalid span has the id active in a trace with no span around it, and an ended root span has it as its
     * parent's.
     */
    #[DataSet([false, false, true], 'not scoped')]
    #[DataSet([true, false, false], 'scoped')]
    #[DataSet([false, true, false], 'not scoped, inside span')]
    public function isTraceInsideInvalidOrEndedRootSpanWithNoSpanAroundIt(bool $scoped, bool $enclosed, bool $isTrace): void
    {
        $run = fn() => $this->tracer->trace('trace', function (SpanInterface $trace) use ($isTrace): void {
            $root = $this->provider->getTracer('test')->spanBuilder('root')->setParent(false)->startSpan();
            $root->end();
            foreach ([OtelSpan::getInvalid(), $root] as $span) {
                $scope = $span->activate();
                try {
                    $isTrace
                        ? Assert::same(CurrentSpan::get(), $trace)
                        : Assert::instanceOf(CurrentSpan::get(), NoopSpan::class);
                } finally {
                    $scope->detach();
                }
            }
        }, scoped: $scoped);

        $enclosed ? $this->inSpan('enclosing', $run) : $run();
    }

    /**
     * The SDK keeps the id of a span's parent only, so an ended span made a child of the trace's span by
     * hand is taken for one activated right inside the trace even while another span runs.
     */
    public function isTraceInsideEndedSpanGivenTraceSpanAsParentByHand(): void
    {
        $this->tracer->trace('trace', function (SpanInterface $trace): void {
            $traceContext = Context::getCurrent();
            $this->inSpan('running', function () use ($trace, $traceContext): void {
                $span = $this->provider->getTracer('test')->spanBuilder('by hand')->setParent($traceContext)->startSpan();
                $span->end();
                $scope = $span->activate();
                try {
                    Assert::same(CurrentSpan::get(), $trace);
                } finally {
                    $scope->detach();
                }
            });
        }, scoped: true);
    }

    private function span(string $name): SpanDataInterface
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        throw new \LogicException("No span named {$name} was exported.");
    }

    /**
     * Runs the callback in an OTel span started and activated by hand, as `#[WithSpan]` does.
     */
    private function inSpan(string $name, \Closure $callback, int $kind = SpanKind::KIND_INTERNAL): void
    {
        $span = $this->provider->getTracer('test')->spanBuilder($name)->setSpanKind($kind)->startSpan();
        $scope = $span->activate();
        try {
            $callback();
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
