<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests;

use Spiral\Telemetry\SpanInterface;
use Testo\Test;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use Testo\Assert;
use Testo\Expect;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeys;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Tracer;

#[Test]
final class TracerTest
{
    public function testTrace(): void
    {
        $tracer = new Tracer(
            new Container(),
            $tracerMockery = Double::for(TracerInterface::class)->strict(),
            Double::for(TextMapPropagatorInterface::class)->strict(),
        );
        $tracerMockery->expects('spanBuilder')->with('test')->returns($spanBuilder = Double::for(SpanBuilderInterface::class)->strict());
        $spanBuilder->allows('setStartTimestamp')->with(9999)->returns($spanBuilder);
        $spanBuilder->expects('setSpanKind')->with(0)->returns($spanBuilder);
        $spanBuilder->expects('startSpan')->returns($span = Double::for(Span::class)->strict());
        $span->expects('updateName')->with('test')->returns($span);
        $span->allows('setAttributes')->with(Argument::satisfies(static fn(mixed $attributes): bool => $attributes == [
            'baz' => 'baf',
            'foo' => 'bar',
            'list' => ['foo', 'baf', 42],
            'kv' => ['key' => 'value', 'foo' => 'bar'],
            'nested' => ['key' => '{"foo":"bar"}'],
            'list-of-one' => 42,
            'stringable' => 'stringable value',
            'jsonable' => '{"foo":{"bar":"baz"}}',
        ]))->returns($span);
        $span->expects('end')->with(Argument::none())->returns($span);
        $result = $tracer->trace(
            'test',
            callback: static function (SpanInterface $span) {
                $span->setAttribute('baz', 'baf');
                $span->setAttribute('list', ['foo', 'baf', 42]);
                $span->setAttribute('kv', ['key' => 'value', 'foo' => 'bar']);
                $span->setAttribute('nested', ['key' => ['foo' => 'bar']]);
                $span->setAttribute('list-of-one', [42]);
                $span->setAttribute('stringable', new class implements \Stringable {
                    public function __toString(): string
                    {
                        return 'stringable value';
                    }
                });
                $span->setAttribute('jsonable', new class implements \JsonSerializable {
                    public function jsonSerialize(): array
                    {
                        return ['foo' => ['bar' => 'baz']];
                    }
                });
                return 'test';
            },
            attributes: ['foo' => 'bar'],
            startTime: 9999,
        );
        Assert::same($result, 'test');
    }

    public function testTraceWithException(): void
    {
        $exception = new \ErrorException('Something went wrong');
        Expect::exception(\ErrorException::class)->withMessageContaining('Something went wrong');
        $tracer = new Tracer(
            new Container(),
            $tracerMockery = Double::for(TracerInterface::class)->strict(),
            Double::for(TextMapPropagatorInterface::class)->strict(),
        );
        $tracerMockery->expects('spanBuilder')->with('test')->returns($spanBuilder = Double::for(SpanBuilderInterface::class)->strict());
        $spanBuilder->allows('setStartTimestamp')->with(9999)->returns($spanBuilder);
        $spanBuilder->expects('setSpanKind')->with(0)->returns($spanBuilder);
        $spanBuilder->expects('startSpan')->returns($span = Double::for(Span::class)->strict());
        $span->expects('end')->with(Argument::none())->returns($span);
        $span->expects('recordException')->with($exception)->returns($span);
        $result = $tracer->trace(
            'test',
            callback: static function () use ($exception) {
                throw $exception;
            },
            attributes: ['foo' => 'bar'],
            startTime: 9999,
        );
        Assert::same($result, 'test');
    }

    public function testScopedTrace(): void
    {
        $tracer = new Tracer(
            new Container(),
            $tracerMockery = Double::for(TracerInterface::class)->strict(),
            Double::for(TextMapPropagatorInterface::class)->strict(),
        );
        $tracerMockery->expects('spanBuilder')->with('test')->returns($spanBuilder = Double::for(SpanBuilderInterface::class)->strict());
        $spanBuilder->allows('setStartTimestamp')->with(9999)->returns($spanBuilder);
        $spanBuilder->expects('setSpanKind')->with(0)->returns($spanBuilder);
        $spanBuilder->expects('startSpan')->returns($span = Double::for(Span::class)->strict());
        $span->expects('updateName')->with('test')->returns($span);
        $span->allows('setAttributes')->with(Argument::satisfies(static fn(mixed $attributes): bool => $attributes == ['baz' => 'baf', 'foo' => 'bar']))->returns($span);
        $span->expects('end')->with(Argument::none())->returns($span);
        // Span::activate() is final, and so is storeInContext() in older open-telemetry/api releases:
        // the span is activated for real, so the test checks the active span instead of the calls
        $span->allows('storeInContext')->resolves(
            static fn(ContextInterface $context): ContextInterface => $context->with(ContextKeys::span(), $span),
        );
        $activeSpan = null;
        $result = $tracer->trace(
            'test',
            callback: static function (SpanInterface $span) use (&$activeSpan) {
                $activeSpan = Span::getCurrent();
                $span->setAttribute('baz', 'baf');
                return 'test';
            },
            attributes: ['foo' => 'bar'],
            scoped: true,
            startTime: 9999,
        );
        Assert::same($result, 'test');
        Assert::same($activeSpan, $span);
        Assert::notSame(Span::getCurrent(), $span);
    }
}
