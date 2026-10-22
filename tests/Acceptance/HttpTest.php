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

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), HttpBootloader::class];
    }

    public function requestWithTraceparentArrivesAsServerSpanOfThatTrace(): void
    {
        $response = $this->fakeHttp()->get('/ping', headers: ['traceparent' => self::TRACEPARENT]);

        $span = $this->traces()->await(2)->server();
        $response->assertOk()->assertBodySame('pong');
        Assert::string($response->getOriginalResponse()->getHeaderLine('traceparent'))->startsWith('00-' . self::TRACE_ID . '-');
        Assert::string($span->name)->startsWith('GET');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
        # spiral/http sets Ok below 500, or leaves it Unset as the HTTP conventions say.
        Assert::contains([ReceivedSpan::STATUS_OK, ReceivedSpan::STATUS_UNSET], $span->statusCode);
        Assert::same($span->httpAttribute('http.request.method', 'http.method'), 'GET');
        Assert::same($span->httpAttribute('http.response.status_code', 'http.status_code'), 200);
    }
}
