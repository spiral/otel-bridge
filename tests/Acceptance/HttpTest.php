<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\HttpBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Testo\Assert;
use Testo\Test;

/**
 * Requests through Spiral\Http\Http, the path production traffic takes: the incoming headers become
 * the tracer context and the request is traced as a scoped SERVER span.
 */
#[Test]
final class HttpTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01';
    private const SERVER_SPAN = 'GET /ping';

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), HttpBootloader::class];
    }

    public function requestWithTraceparentArrivesAsServerSpanOfThatTrace(): void
    {
        $response = $this->fakeHttp()->get('/ping', headers: ['traceparent' => self::TRACEPARENT]);

        $span = $this->traces()->await(2)->named(self::SERVER_SPAN);
        $response->assertOk()->assertBodySame('pong');
        Assert::string($response->getOriginalResponse()->getHeaderLine('traceparent'))->startsWith('00-' . self::TRACE_ID . '-');
        Assert::same($span->kind, ReceivedSpan::KIND_SERVER);
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($span->statusCode, ReceivedSpan::STATUS_OK);
        Assert::same($span->attributes['http.method'] ?? null, 'GET');
        Assert::same($span->attributes['http.status_code'] ?? null, 200);
    }
}
