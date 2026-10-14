<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * Spans whose callback throws, exported over OTLP/HTTP and read back from Trap.
 *
 * Expectations follow https://opentelemetry.io/docs/specs/semconv/general/recording-errors/ (status Error,
 * description = exception message, handled errors not recorded), https://opentelemetry.io/docs/specs/otel/trace/api/#set-status
 * (Ok is final, Unset is ignored) and https://opentelemetry.io/docs/specs/otel/trace/exceptions/ (the exception event).
 */
#[Test]
final class ExceptionTest extends TestCase
{
    #[Skip('Known issue: a throwing callback leaves the span Unset, see https://github.com/spiral/otel-bridge/issues/18')]
    #[DataSet([\RuntimeException::class, 'Payment declined'], 'runtime exception')]
    #[DataSet([\TypeError::class, 'Argument #1 must be of type int'], 'error, not an exception')]
    #[DataSet([\DomainException::class, 'Nested "quotes" and ünïcödé'], 'message with quotes and unicode')]
    public function throwingCallbackMarksSpanAsErrorWithExceptionMessage(string $class, string $message): void
    {
        self::rescue(fn() => $this->tracer()->trace('charge', static fn() => throw new $class($message)));

        $span = $this->traces()->await(1)->named('charge');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, $message);
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset, see https://github.com/spiral/otel-bridge/issues/18')]
    public function exceptionWithEmptyMessageStillMarksSpanAsError(): void
    {
        self::rescue(fn() => $this->tracer()->trace('silent', static fn() => throw new \LogicException()));

        $span = $this->traces()->await(1)->named('silent');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, '');
    }

    #[DataSet([\RuntimeException::class, 'Payment declined'], 'runtime exception')]
    #[DataSet([\TypeError::class, 'Argument #1 must be of type int'], 'error, not an exception')]
    public function exceptionIsRecordedAsSingleEventWithTypeMessageAndStacktrace(string $class, string $message): void
    {
        self::rescue(fn() => $this->tracer()->trace('charge', static fn() => throw new $class($message)));

        $span = $this->traces()->await(1)->named('charge');
        Assert::count($span->events, 1);
        $event = $span->events[0];
        Assert::same($event['name'], 'exception');
        Assert::same($event['attributes']['exception.type'] ?? null, $class);
        Assert::same($event['attributes']['exception.message'] ?? null, $message);
        Assert::string($event['attributes']['exception.stacktrace'] ?? '')
            ->contains($class)
            ->contains(\basename(__FILE__));
        Assert::int($event['timeUnixNano'])
            ->greaterThanOrEqual($span->startTimeUnixNano)
            ->lessThanOrEqual($span->endTimeUnixNano);
    }

    /**
     * The event describes the thrown exception; the chained one shows up only in its stacktrace.
     */
    public function exceptionWithPreviousIsRecordedByTheOuterException(): void
    {
        self::rescue(fn() => $this->tracer()->trace('import', static function (): never {
            try {
                throw new \InvalidArgumentException('Row 7 is malformed');
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('Import failed', 0, $e);
            }
        }));

        $span = $this->traces()->await(1)->named('import');
        Assert::count($span->events, 1);
        $attributes = $span->events[0]['attributes'];
        Assert::same($attributes['exception.type'] ?? null, \RuntimeException::class);
        Assert::same($attributes['exception.message'] ?? null, 'Import failed');
        Assert::string($attributes['exception.stacktrace'] ?? '')
            ->contains(\InvalidArgumentException::class)
            ->contains('Row 7 is malformed');
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset, see https://github.com/spiral/otel-bridge/issues/18')]
    public function exceptionWithPreviousUsesOuterMessageAsDescription(): void
    {
        self::rescue(fn() => $this->tracer()->trace('import', static fn() => throw new \RuntimeException(
            'Import failed',
            0,
            new \InvalidArgumentException('Row 7 is malformed'),
        )));

        $span = $this->traces()->await(1)->named('import');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, 'Import failed');
    }

    public function exceptionIsRethrownUnchanged(): void
    {
        $thrown = new \RuntimeException('Boom', 42, new \LogicException('cause'));

        $caught = self::rescue(fn() => $this->tracer()->trace('rethrow', static fn() => throw $thrown));

        Assert::same($caught, $thrown);
        Assert::same($caught->getMessage(), 'Boom');
        Assert::same($caught->getCode(), 42);
        Assert::same($caught->getPrevious()?->getMessage(), 'cause');
    }

    public function throwingCallbackExportsExactlyOneEndedSpan(): void
    {
        self::rescue(fn() => $this->tracer()->trace('once', static fn() => throw new \RuntimeException('fail')));

        $spans = $this->traces()->await(1);
        Assert::count($spans, 1, "Received:\n{$spans}");
        Assert::int($spans->named('once')->endTimeUnixNano)->greaterThanOrEqual($spans->named('once')->startTimeUnixNano);
    }

    #[Skip('Known issue: a throwing callback loses the name set inside it, see https://github.com/spiral/otel-bridge/issues/18')]
    public function nameUpdatedInsideCallbackSurvivesTheThrow(): void
    {
        self::rescue(fn() => $this->tracer()->trace('draft', static function (SpanInterface $span): never {
            $span->updateName('final');
            throw new \RuntimeException('fail');
        }));

        $spans = $this->traces()->await(1);
        Assert::count($spans, 1, "Received:\n{$spans}");
        Assert::same($spans->spans[0]->name, 'final');
    }

    public function nameGivenToTraceIsKeptWhenCallbackThrowsWithoutRenaming(): void
    {
        self::rescue(fn() => $this->tracer()->trace('keep-me', static fn() => throw new \RuntimeException('fail')));

        Assert::same($this->traces()->await(1)->spans[0]->name, 'keep-me');
    }

    #[Skip('Known issue: a throwing callback loses the attributes passed to trace(), see https://github.com/spiral/otel-bridge/issues/18')]
    public function attributesPassedToTraceSurviveTheThrow(): void
    {
        self::rescue(fn() => $this->tracer()->trace(
            'checkout',
            static fn() => throw new \RuntimeException('fail'),
            attributes: ['user.role' => 'admin', 'cart.size' => 3],
        ));

        $span = $this->traces()->await(1)->named('checkout');
        Assert::same($span->attributes['user.role'] ?? null, 'admin');
        Assert::same($span->attributes['cart.size'] ?? null, 3);
    }

    #[Skip('Known issue: a throwing callback loses the attributes set inside it, see https://github.com/spiral/otel-bridge/issues/18')]
    public function attributesSetInsideCallbackSurviveTheThrowAndOverrideInitialOnes(): void
    {
        self::rescue(fn() => $this->tracer()->trace(
            'checkout',
            static function (SpanInterface $span): never {
                $span->setAttribute('order.id', 42);
                $span->setAttribute('user.role', 'guest');
                $span->setAttribute('paid', false);
                throw new \RuntimeException('fail');
            },
            attributes: ['user.role' => 'admin', 'channel' => 'web'],
        ));

        $span = $this->traces()->await(1)->named('checkout');
        Assert::same($span->attributes['order.id'] ?? null, 42);
        Assert::same($span->attributes['user.role'] ?? null, 'guest');
        Assert::same($span->attributes['paid'] ?? null, false);
        Assert::same($span->attributes['channel'] ?? null, 'web');
    }

    /**
     * `setAttributes()` replaces the whole set, so an initial attribute it drops must not come back.
     */
    #[Skip('Known issue: a throwing callback loses the attributes set inside it, see https://github.com/spiral/otel-bridge/issues/18')]
    public function attributesReplacedInsideCallbackSurviveTheThrow(): void
    {
        self::rescue(fn() => $this->tracer()->trace(
            'replace',
            static function (SpanInterface $span): never {
                $span->setAttributes(['only' => 'this']);
                throw new \RuntimeException('fail');
            },
            attributes: ['dropped' => 'yes'],
        ));

        $span = $this->traces()->await(1)->named('replace');
        Assert::same($span->attributes['only'] ?? null, 'this');
        Assert::false(\array_key_exists('dropped', $span->attributes));
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset and loses its name and attributes, see https://github.com/spiral/otel-bridge/issues/18')]
    public function throwingServerSpanKeepsKindAndEverythingSetBeforeTheThrow(): void
    {
        self::rescue(fn() => $this->tracer()->trace(
            'GET /orders',
            static function (SpanInterface $span): never {
                $span->updateName('GET /orders/{id}')->setAttribute('http.response.status_code', 500);
                throw new \RuntimeException('Order storage is down');
            },
            attributes: ['http.request.method' => 'GET'],
            traceKind: TraceKind::SERVER,
        ));

        $span = $this->traces()->await(1)->named('GET /orders/{id}');
        Assert::same($span->kind, ReceivedSpan::KIND_SERVER);
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, 'Order storage is down');
        Assert::same($span->attributes['http.request.method'] ?? null, 'GET');
        Assert::same($span->attributes['http.response.status_code'] ?? null, 500);
        Assert::count($span->events, 1);
    }

    /**
     * Ok is final: https://opentelemetry.io/docs/specs/otel/trace/api/#set-status
     *
     * @todo Question: Ok set inside the callback stays Ok after a throw (the spec makes Ok final);
     *       #18 only mentions keeping a user-set Error. Confirm Ok should win over the exception.
     */
    #[Skip('Known issue: a throwing callback drops the status set inside it, see https://github.com/spiral/otel-bridge/issues/18')]
    public function okStatusSetByCallbackIsFinalEvenWhenItThrows(): void
    {
        self::rescue(fn() => $this->tracer()->trace('ok-then-throw', static function (SpanInterface $span): never {
            $span->setStatus('ok');
            throw new \RuntimeException('late failure');
        }));

        $span = $this->traces()->await(1)->named('ok-then-throw');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_OK);
        Assert::same($span->statusMessage, '');
        Assert::same($span->events[0]['name'] ?? null, 'exception');
    }

    /**
     * Setting Unset is ignored: https://opentelemetry.io/docs/specs/otel/trace/api/#set-status
     */
    #[Skip('Known issue: a throwing callback leaves the span Unset, see https://github.com/spiral/otel-bridge/issues/18')]
    public function unsetStatusSetByCallbackDoesNotHideTheError(): void
    {
        self::rescue(fn() => $this->tracer()->trace('unset-then-throw', static function (SpanInterface $span): never {
            $span->setStatus('unset');
            throw new \RuntimeException('fail');
        }));

        $span = $this->traces()->await(1)->named('unset-then-throw');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, 'fail');
    }

    /**
     * The spec lets either description win, so both the callback's and the exception's are accepted.
     */
    #[Skip('Known issue: a throwing callback drops the status set inside it, see https://github.com/spiral/otel-bridge/issues/18')]
    public function errorStatusSetByCallbackStaysErrorWhenItThrows(): void
    {
        self::rescue(fn() => $this->tracer()->trace('error-then-throw', static function (SpanInterface $span): never {
            $span->setStatus('error', 'Card expired');
            throw new \RuntimeException('Gateway rejected the charge');
        }));

        $span = $this->traces()->await(1)->named('error-then-throw');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::contains(['Card expired', 'Gateway rejected the charge'], $span->statusMessage);
    }

    /**
     * Handled errors are not recorded on the operation that handled them:
     * https://opentelemetry.io/docs/specs/semconv/general/recording-errors/
     */
    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function outerSpanThatCatchesInnerExceptionStaysUnsetWithoutEvents(bool $scoped): void
    {
        $tracer = $this->tracer();

        $result = $tracer->trace('outer', static function () use ($tracer, $scoped): string {
            try {
                $tracer->trace('inner', static fn() => throw new \RuntimeException('inner failed'), scoped: $scoped);
            } catch (\RuntimeException) {
                return 'recovered';
            }

            return 'unreachable';
        }, scoped: $scoped);

        $spans = $this->traces()->await(2);
        $outer = $spans->named('outer');
        Assert::same($result, 'recovered');
        Assert::count($spans, 2, "Received:\n{$spans}");
        Assert::same($outer->statusCode, ReceivedSpan::STATUS_UNSET);
        Assert::same($outer->events, []);
        Assert::same($spans->named('inner')->events[0]['attributes']['exception.message'] ?? null, 'inner failed');
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset, see https://github.com/spiral/otel-bridge/issues/18')]
    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function innerSpanIsErrorWhileOuterThatCatchesStaysUnset(bool $scoped): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', static function (SpanInterface $span) use ($tracer, $scoped): void {
            try {
                $tracer->trace('inner', static fn() => throw new \RuntimeException('inner failed'), scoped: $scoped);
            } catch (\RuntimeException) {
                $span->setAttribute('recovered', true);
            }
        }, scoped: $scoped);

        $spans = $this->traces()->await(2);
        $inner = $spans->named('inner');
        $outer = $spans->named('outer');
        Assert::same($inner->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($inner->statusMessage, 'inner failed');
        Assert::same($outer->statusCode, ReceivedSpan::STATUS_UNSET);
        Assert::same($outer->attributes['recovered'] ?? null, true);
    }

    public function failingInnerSpanStaysChildOfOuterAndScopeIsRestoredForItsSibling(): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', static function () use ($tracer): void {
            try {
                $tracer->trace('failing', static fn() => throw new \RuntimeException('fail'), scoped: true);
            } catch (\RuntimeException) {
            }

            $tracer->trace('sibling', static fn() => null, scoped: true);
        }, scoped: true);
        $tracer->trace('after', static fn() => null, scoped: true);

        $spans = $this->traces()->await(4);
        $outer = $spans->named('outer');
        $failing = $spans->named('failing');
        $sibling = $spans->named('sibling');
        $after = $spans->named('after');
        Assert::count($spans, 4, "Received:\n{$spans}");
        Assert::true($outer->isRoot());
        Assert::same($failing->parentSpanId, $outer->spanId);
        Assert::same($sibling->parentSpanId, $outer->spanId);
        Assert::same($failing->traceId, $outer->traceId);
        Assert::same($sibling->traceId, $outer->traceId);
        Assert::true($after->isRoot());
        Assert::notSame($after->traceId, $outer->traceId);
    }

    public function scopeIsRestoredAfterScopedRootSpanThrows(): void
    {
        $tracer = $this->tracer();

        self::rescue(static fn() => $tracer->trace('failed-root', static fn() => throw new \RuntimeException('fail'), scoped: true));
        $tracer->trace('next-root', static fn() => null, scoped: true);

        $spans = $this->traces()->await(2);
        $failed = $spans->named('failed-root');
        $next = $spans->named('next-root');
        Assert::true($failed->isRoot());
        Assert::true($next->isRoot());
        Assert::notSame($next->traceId, $failed->traceId);
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset and loses its name, see https://github.com/spiral/otel-bridge/issues/18')]
    #[DataSet([true], 'scoped')]
    #[DataSet([false], 'not scoped')]
    public function exceptionPropagatingThroughBothSpansMarksBothAsError(bool $scoped): void
    {
        $tracer = $this->tracer();

        $caught = self::rescue(static fn() => $tracer->trace(
            'outer',
            static function (SpanInterface $span) use ($tracer, $scoped): never {
                $span->updateName('outer-renamed');
                try {
                    $tracer->trace('inner', static fn() => throw new \RuntimeException('db down'), scoped: $scoped);
                } catch (\RuntimeException $e) {
                    throw new \LogicException('request failed', 0, $e);
                }
            },
            scoped: $scoped,
        ));

        $spans = $this->traces()->await(2);
        $inner = $spans->named('inner');
        $outer = $spans->named('outer-renamed');
        Assert::instanceOf($caught, \LogicException::class);
        Assert::same($inner->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($inner->statusMessage, 'db down');
        Assert::same($outer->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($outer->statusMessage, 'request failed');
        Assert::same($outer->events[0]['attributes']['exception.type'] ?? null, \LogicException::class);
        Assert::same($inner->events[0]['attributes']['exception.type'] ?? null, \RuntimeException::class);
    }

    #[Skip('Known issue: a throwing callback leaves the span Unset, see https://github.com/spiral/otel-bridge/issues/18')]
    public function sameExceptionRethrownThroughNestedSpansMarksEachAsErrorOnce(): void
    {
        $tracer = $this->tracer();

        self::rescue(static fn() => $tracer->trace(
            'outer',
            static fn() => $tracer->trace('inner', static fn() => throw new \RuntimeException('fail'), scoped: true),
            scoped: true,
        ));

        $spans = $this->traces()->await(2);
        Assert::count($spans, 2, "Received:\n{$spans}");
        foreach (['inner', 'outer'] as $name) {
            $span = $spans->named($name);
            Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR, "{$name} status");
            Assert::same($span->statusMessage, 'fail', "{$name} description");
            Assert::count($span->events, 1, "{$name} events");
        }
    }

    /**
     * Guards the success path against a fix that sets Error unconditionally (for example in `finally`).
     */
    public function successfulCallbackStaysUnsetWithoutEvents(): void
    {
        $this->tracer()->trace('fine', static function (SpanInterface $span): void {
            $span->updateName('fine-renamed')->setAttribute('k', 'v');
        });

        $span = $this->traces()->await(1)->named('fine-renamed');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_UNSET);
        Assert::same($span->statusMessage, '');
        Assert::same($span->events, []);
        Assert::same($span->attributes['k'] ?? null, 'v');
    }

    /**
     * Guards against a fix that records a handled exception: the callback catches its own failure.
     */
    public function exceptionHandledInsideCallbackIsNotRecorded(): void
    {
        $result = $this->tracer()->trace('handled', static function (): string {
            try {
                throw new \RuntimeException('retry me');
            } catch (\RuntimeException) {
                return 'retried';
            }
        });

        $span = $this->traces()->await(1)->named('handled');
        Assert::same($result, 'retried');
        Assert::same($span->statusCode, ReceivedSpan::STATUS_UNSET);
        Assert::same($span->events, []);
    }

    /**
     * Runs a call expected to throw and hands back what it threw, so the test can go on to the spans.
     */
    private static function rescue(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
