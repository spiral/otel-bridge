<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use Spiral\Core\Container;
use Spiral\OpenTelemetry\Tracer;
use Mockery as m;

class TracerTest extends TestCase
{
    public function testTrace(): void
    {
        $tracer = new Tracer(
            new Container(),
            $tracerMockery = m::mock(TracerInterface::class),
            m::mock(TextMapPropagatorInterface::class),
        );

        $tracerMockery->shouldReceive('spanBuilder')->once()->with('test')->andReturn(
            $spanBuilder = m::mock(SpanBuilderInterface::class),
        );

        $spanBuilder->shouldReceive('setStartTimestamp')->with(9999)->andReturnSelf();
        $spanBuilder->shouldReceive('setSpanKind')->once()->with(null)->andReturnSelf();
        $spanBuilder->shouldReceive('startSpan')->once()->andReturn($span = m::mock(SpanInterface::class));

        $span->shouldReceive('updateName')->once()->with('test')->andReturnSelf();
        $span->shouldReceive('setAttributes')->with([
            'baz' => 'baf',
            'foo' => 'bar',
            'list' => ['foo', 'baf', 42],
            'kv' => ['key' => 'value', 'foo' => 'bar'],
            'nested' => ['key' => '{"foo":"bar"}'],
            'list-of-one' => 42,
            'stringable' => 'stringable value',
            'jsonable' => '{"foo":{"bar":"baz"}}',
        ])->andReturnSelf();
        $span->shouldReceive('end')->once()->withNoArgs()->andReturnSelf();

        $result = $tracer->trace(
            'test',
            callback: static function (\Spiral\Telemetry\SpanInterface $span) {
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

        $this->assertSame('test', $result);
    }

    public function testTraceWithException(): void
    {
        $exception = new \ErrorException('Something went wrong');

        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Something went wrong');

        $tracer = new Tracer(
            new Container(),
            $tracerMockery = m::mock(TracerInterface::class),
            m::mock(TextMapPropagatorInterface::class),
        );

        $tracerMockery->shouldReceive('spanBuilder')->once()->with('test')->andReturn(
            $spanBuilder = m::mock(SpanBuilderInterface::class),
        );

        $spanBuilder->shouldReceive('setStartTimestamp')->with(9999)->andReturnSelf();
        $spanBuilder->shouldReceive('setSpanKind')->once()->with(null)->andReturnSelf();
        $spanBuilder->shouldReceive('startSpan')->once()->andReturn($span = m::mock(SpanInterface::class));

        $span->shouldReceive('end')->once()->withNoArgs()->andReturnSelf();

        $span->shouldReceive('recordException')->once()->with($exception)->andReturnSelf();

        $result = $tracer->trace(
            'test',
            callback: static function () use ($exception) {
                throw $exception;
            },
            attributes: ['foo' => 'bar'],
            startTime: 9999,
        );

        $this->assertSame('test', $result);
    }

    public function testScopedTrace(): void
    {
        $tracer = new Tracer(
            new Container(),
            $tracerMockery = m::mock(TracerInterface::class),
            m::mock(TextMapPropagatorInterface::class),
        );

        $tracerMockery->shouldReceive('spanBuilder')->once()->with('test')->andReturn(
            $spanBuilder = m::mock(SpanBuilderInterface::class),
        );

        $spanBuilder->shouldReceive('setStartTimestamp')->with(9999)->andReturnSelf();
        $spanBuilder->shouldReceive('setSpanKind')->once()->with(null)->andReturnSelf();
        $spanBuilder->shouldReceive('startSpan')->once()->andReturn($span = m::mock(SpanInterface::class));

        $span->shouldReceive('updateName')->once()->with('test')->andReturnSelf();
        $span->shouldReceive('setAttributes')->with(['baz' => 'baf', 'foo' => 'bar'])->andReturnSelf();
        $span->shouldReceive('end')->once()->withNoArgs()->andReturnSelf();
        $span->shouldReceive('activate')->withNoArgs()->andReturn($scope = m::mock(ScopeInterface::class));

        $scope->shouldReceive('detach')->withNoArgs()->andReturn();

        $result = $tracer->trace(
            'test',
            callback: static function (\Spiral\Telemetry\SpanInterface $span) {
                $span->setAttribute('baz', 'baf');
                return 'test';
            },
            attributes: ['foo' => 'bar'],
            scoped: true,
            startTime: 9999,
        );

        $this->assertSame('test', $result);
    }
}
