<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\SpiralIntegrationAudit;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\SpiralIntegrationBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\SpiralIntegrationMailJob;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanList;
use Spiral\Queue\QueueInterface;
use Spiral\Telemetry\CurrentSpan;
use Spiral\Telemetry\Span\NoopSpan;
use Testo\Assert;
use Testo\Test;

/**
 * `CurrentSpan::get()` in services of the app of {@see SpiralIntegrationTest}, called from an HTTP handler
 * and a queue job: {@see SpiralIntegrationAudit} records on whatever span it runs in.
 */
#[Test]
final class CurrentSpanIntegrationTest extends TestCase
{
    private const PIPELINE_SPAN = 'HTTP Pipeline';
    private const PUSH_SPAN = 'Job push [mail.send]';
    private const JOB_SPAN = 'Job handling [mail.send]';

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), SpiralIntegrationBootloader::class];
    }

    /**
     * The handler runs inside the `HTTP Pipeline` span, not the SERVER span around it.
     */
    public function serviceCalledFromHandlerRecordsOnPipelineSpan(): void
    {
        $this->fakeHttp()->get('/audit')->assertOk()->assertBodySame('audited');

        $spans = $this->traces()->await(4);
        Assert::count($spans, 4, "Received:\n{$spans}");
        self::assertOnlyOn($spans, 'order.status', self::PIPELINE_SPAN, 'paid');
    }

    public function serviceCalledInTraceOfHandlerRecordsOnThatTraceEvenNotScoped(): void
    {
        $this->fakeHttp()->get('/audit')->assertOk();

        $spans = $this->traces()->await(4);
        Assert::same($spans->named('count orders')->parentSpanId, $spans->named(self::PIPELINE_SPAN)->spanId);
        self::assertOnlyOn($spans, 'order.count', 'count orders', 3);
    }

    /**
     * See {@see SpiralIntegrationMailJob}: outside its own `deliver` trace the job runs in the CONSUMER span.
     */
    public function serviceCalledFromJobRecordsOnJobSpan(): void
    {
        $this->getContainer()->get(QueueInterface::class)->push('mail.send', ['to' => 'alice@example.com']);

        $spans = $this->traces()->await(3);
        Assert::count($spans, 3, "Received:\n{$spans}");
        self::assertOnlyOn($spans, 'mail.to', self::JOB_SPAN, 'alice@example.com');
        self::assertOnlyOn($spans, 'mail.delivered', 'deliver', true);
    }

    /**
     * The job of the sync driver runs inside the push span inside the pipeline: the job span is innermost.
     */
    public function serviceCalledFromJobPushedByRequestRecordsOnJobSpan(): void
    {
        $this->fakeHttp()->post('/orders', ['to' => 'carol@example.com'])->assertStatus(201);

        $spans = $this->traces()->await(6);
        Assert::count($spans, 6, "Received:\n{$spans}");
        Assert::same($spans->named(self::JOB_SPAN)->parentSpanId, $spans->named(self::PUSH_SPAN)->spanId);
        self::assertOnlyOn($spans, 'mail.to', self::JOB_SPAN, 'carol@example.com');
        self::assertOnlyOn($spans, 'mail.delivered', 'deliver', true);
    }

    public function outsideAnyTraceIsNoopAndExportsNothing(): void
    {
        $span = CurrentSpan::get();
        $this->getContainer()->get(SpiralIntegrationAudit::class)->record('order.status', 'paid');

        Assert::same($span, NoopSpan::instance());
        Assert::count($this->traces()->settled(), 0);
    }

    public function afterRequestIsNoopAndAddsNothingToItsSpans(): void
    {
        $this->fakeHttp()->get('/audit')->assertOk();

        $span = CurrentSpan::get();
        $this->getContainer()->get(SpiralIntegrationAudit::class)->record('order.late', true);

        $spans = $this->traces()->settled();
        Assert::same($span, NoopSpan::instance());
        Assert::count($spans, 4, "Received:\n{$spans}");
        foreach ($spans as $received) {
            Assert::false(\array_key_exists('order.late', $received->attributes), "Span {$received->name}:\n{$spans}");
        }
    }

    private static function assertOnlyOn(SpanList $spans, string $key, string $spanName, mixed $expected): void
    {
        Assert::same($spans->named($spanName)->attributes[$key] ?? null, $expected, "Received:\n{$spans}");
        foreach ($spans as $span) {
            if ($span->name !== $spanName) {
                Assert::false(\array_key_exists($key, $span->attributes), "Span {$span->name} got {$key}:\n{$spans}");
            }
        }
    }
}
