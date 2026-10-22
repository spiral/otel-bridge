<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use OpenTelemetry\API\Trace\StatusCode;
use Spiral\OpenTelemetry\Tests\Acceptance\Fixture\StatusCodeHttpBootloader;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Skip;
use Testo\Test;

/**
 * How the status a Spiral span is given, a string or an int, arrives as the OTLP span status.
 *
 * Expectations follow the trace API (https://opentelemetry.io/docs/specs/otel/trace/api/#set-status):
 * `Ok` is set only explicitly, a description survives only on `Error`; and for integer HTTP codes the
 * HTTP semantic conventions (https://opentelemetry.io/docs/specs/semconv/http/http-spans/#status).
 */
#[Test]
final class StatusCodeTest extends TestCase
{
    private const SPAN = 'status';

    public static function httpServerResponses(): iterable
    {
        yield '200' => [200, false];
        yield '204' => [204, false];
        yield '301' => [301, false];
        yield '400' => [400, false];
        yield '404' => [404, false];
        yield '418' => [418, false];
        yield '499' => [499, false];
        yield '500' => [500, true];
        yield '503' => [503, true];
        yield '599' => [599, true];
    }

    public static function httpStatusCodesByKind(): iterable
    {
        foreach ([100, 200, 204, 301] as $code) {
            yield "{$code} internal" => [$code, null, ReceivedSpan::STATUS_UNSET];
            yield "{$code} server" => [$code, TraceKind::SERVER, ReceivedSpan::STATUS_UNSET];
            yield "{$code} client" => [$code, TraceKind::CLIENT, ReceivedSpan::STATUS_UNSET];
        }

        foreach ([400, 404, 418, 499] as $code) {
            yield "{$code} server" => [$code, TraceKind::SERVER, ReceivedSpan::STATUS_UNSET];
            yield "{$code} client" => [$code, TraceKind::CLIENT, ReceivedSpan::STATUS_ERROR];
        }

        foreach ([500, 503, 599] as $code) {
            yield "{$code} internal" => [$code, null, ReceivedSpan::STATUS_ERROR];
            yield "{$code} server" => [$code, TraceKind::SERVER, ReceivedSpan::STATUS_ERROR];
            yield "{$code} client" => [$code, TraceKind::CLIENT, ReceivedSpan::STATUS_ERROR];
            yield "{$code} producer" => [$code, TraceKind::PRODUCER, ReceivedSpan::STATUS_ERROR];
            yield "{$code} consumer" => [$code, TraceKind::CONSUMER, ReceivedSpan::STATUS_ERROR];
        }

        yield '0 internal' => [0, null, ReceivedSpan::STATUS_UNSET];
        yield '0 server' => [0, TraceKind::SERVER, ReceivedSpan::STATUS_UNSET];
        yield '0 client' => [0, TraceKind::CLIENT, ReceivedSpan::STATUS_UNSET];
    }

    public function defineBootloaders(): array
    {
        return [...parent::defineBootloaders(), StatusCodeHttpBootloader::class];
    }

    public function spanWithoutStatusArrivesUnset(): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setAttribute('k', 'v'));

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->statusCode, ReceivedSpan::STATUS_UNSET);
        Assert::same($span->statusMessage, '');
    }

    #[DataSet(['ok', ReceivedSpan::STATUS_OK], 'ok')]
    #[DataSet(['OK', ReceivedSpan::STATUS_OK], 'OK as spiral/http sends it')]
    #[DataSet([StatusCode::STATUS_OK, ReceivedSpan::STATUS_OK], 'API constant Ok')]
    #[DataSet(['error', ReceivedSpan::STATUS_ERROR], 'error')]
    #[DataSet(['ERROR', ReceivedSpan::STATUS_ERROR], 'ERROR as spiral/http sends it')]
    #[DataSet([StatusCode::STATUS_ERROR, ReceivedSpan::STATUS_ERROR], 'API constant Error')]
    #[DataSet(['unset', ReceivedSpan::STATUS_UNSET], 'unset')]
    #[DataSet(['UNSET', ReceivedSpan::STATUS_UNSET], 'UNSET')]
    #[DataSet([StatusCode::STATUS_UNSET, ReceivedSpan::STATUS_UNSET], 'API constant Unset')]
    public function statusNameIsMappedCaseInsensitively(string $code, int $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code));

        Assert::same($this->traces()->await(1)->named(self::SPAN)->statusCode, $expected);
    }

    /**
     * Description MUST be ignored for Ok and Unset: https://opentelemetry.io/docs/specs/otel/trace/api/#set-status
     */
    #[DataSet(['error', 'Payment declined', ReceivedSpan::STATUS_ERROR, 'Payment declined'], 'error keeps it')]
    #[DataSet(['ok', 'All good', ReceivedSpan::STATUS_OK, ''], 'ok drops it')]
    #[DataSet(['unset', 'Nothing yet', ReceivedSpan::STATUS_UNSET, ''], 'unset drops it')]
    public function descriptionArrivesOnlyWithError(string $code, string $description, int $expectedCode, string $expectedMessage): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code, $description));

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->statusCode, $expectedCode);
        Assert::same($span->statusMessage, $expectedMessage);
    }

    /**
     * A Spiral span keeps only the last status it was given, so the bridge must not apply an earlier
     * one to the OpenTelemetry span, where `Ok` would be final.
     */
    #[DataSet([['ok', 'error'], ReceivedSpan::STATUS_ERROR], 'ok then error')]
    #[DataSet([['error', 'ok'], ReceivedSpan::STATUS_OK], 'error then ok')]
    #[DataSet([['error', 'unset'], ReceivedSpan::STATUS_UNSET], 'error then unset')]
    #[DataSet([[500, 'ok'], ReceivedSpan::STATUS_OK], 'int 500 then ok')]
    #[DataSet([[500, 'unset'], ReceivedSpan::STATUS_UNSET], 'int 500 then unset')]
    public function lastStatusSetInsideCallbackWins(array $codes, int $expected): void
    {
        $this->tracer()->trace(self::SPAN, static function (SpanInterface $span) use ($codes): void {
            foreach ($codes as $code) {
                $span->setStatus($code);
            }
        });

        Assert::same($this->traces()->await(1)->named(self::SPAN)->statusCode, $expected);
    }

    #[DataProvider('httpServerResponses')]
    public function serverSpanOfRequestIsErrorOnlyFor5xxResponse(int $code, bool $isError): void
    {
        $response = $this->fakeHttp()->get('/', query: ['code' => (string) $code]);

        $span = $this->traces()->await(1)->server();
        $response->assertStatus($code);
        Assert::same($span->httpAttribute('http.response.status_code', 'http.status_code'), $code);
        $isError
            ? Assert::same($span->statusCode, ReceivedSpan::STATUS_ERROR)
            : Assert::notSame($span->statusCode, ReceivedSpan::STATUS_ERROR);
        Assert::same($span->statusMessage, '');
    }

    /**
     * `spiral/http` sets 'OK' itself, which the bridge cannot tell from an explicit Ok.
     */
    #[DataProvider('httpServerResponses')]
    #[Skip('Known issue: spiral/http sets status OK for every response below 500, see https://github.com/spiral/framework/issues/1311')]
    public function serverSpanOfRequestIsUnsetForNon5xxResponse(int $code, bool $isError): void
    {
        $this->fakeHttp()->get('/', query: ['code' => (string) $code]);

        $span = $this->traces()->await(1)->server();
        Assert::same($span->statusCode, $isError ? ReceivedSpan::STATUS_ERROR : ReceivedSpan::STATUS_UNSET);
    }

    #[Env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')]
    #[DataSet(['error', 'Payment declined', ReceivedSpan::STATUS_ERROR, 'Payment declined'], 'error')]
    #[DataSet(['ok', 'All good', ReceivedSpan::STATUS_OK, ''], 'ok')]
    #[DataSet(['unset', 'Nothing yet', ReceivedSpan::STATUS_UNSET, ''], 'unset')]
    public function statusArrivesOverProtobuf(string $code, string $description, int $expectedCode, string $expectedMessage): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code, $description));

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->statusCode, $expectedCode);
        Assert::same($span->statusMessage, $expectedMessage);
    }

    #[DataSet(['failed'], 'failed')]
    #[DataSet(['foo'], 'foo')]
    #[DataSet(['okay'], 'okay')]
    #[DataSet(['success'], 'success')]
    #[DataSet(['true'], 'true')]
    #[DataSet([''], 'empty string')]
    public function unknownStatusNameArrivesUnset(string $code): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code, 'ignored'));

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->statusCode, ReceivedSpan::STATUS_UNSET);
        Assert::same($span->statusMessage, '');
    }

    /**
     * Whether these are trimmed or parsed is the bridge's choice, but none of them asks for `Ok`.
     */
    #[DataSet([' error '], 'padded error')]
    #[DataSet(["\terror\n"], 'error with tab and newline')]
    #[DataSet(['500'], 'numeric string 500')]
    #[DataSet(['404'], 'numeric string 404')]
    #[DataSet(['0'], 'numeric string 0')]
    public function ambiguousStatusNameIsNeverOk(string $code): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code));

        Assert::notSame($this->traces()->await(1)->named(self::SPAN)->statusCode, ReceivedSpan::STATUS_OK);
    }

    /**
     * https://opentelemetry.io/docs/specs/semconv/http/http-spans/#status: 1xx-3xx leave the status unset,
     * 4xx is Error for CLIENT and unset for SERVER, 5xx is Error.
     */
    #[DataProvider('httpStatusCodesByKind')]
    public function integerHttpCodeFollowsHttpSemanticConventions(int $code, ?TraceKind $kind, int $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code), traceKind: $kind);

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->statusCode, $expected);
        Assert::same($span->statusMessage, '');
    }

    /**
     * Codes the HTTP conventions leave open are Error: 4xx fails every span but a SERVER one, a code out of
     * the HTTP range cannot be interpreted, and 2 is the OTLP enum value of Error.
     */
    #[DataSet([404, null], '404 internal')]
    #[DataSet([404, TraceKind::PRODUCER], '404 producer')]
    #[DataSet([418, TraceKind::CONSUMER], '418 consumer')]
    #[DataSet([-1, null], '-1 internal')]
    #[DataSet([-1, TraceKind::CLIENT], '-1 client')]
    #[DataSet([600, TraceKind::SERVER], '600 server')]
    #[DataSet([600, TraceKind::CLIENT], '600 client')]
    #[DataSet([1000, null], '1000 internal')]
    #[DataSet([2, null], 'OTLP Error enum')]
    #[DataSet([\PHP_INT_MAX, TraceKind::SERVER], 'PHP_INT_MAX server')]
    #[DataSet([\PHP_INT_MIN, TraceKind::CLIENT], 'PHP_INT_MIN client')]
    public function integerCodeOutsideConventionsIsError(int $code, ?TraceKind $kind): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code), traceKind: $kind);

        Assert::same($this->traces()->await(1)->named(self::SPAN)->statusCode, ReceivedSpan::STATUS_ERROR);
    }

    #[DataSet([0, ReceivedSpan::STATUS_UNSET], 'Unset')]
    #[DataSet([1, ReceivedSpan::STATUS_OK], 'Ok')]
    #[DataSet([2, ReceivedSpan::STATUS_ERROR], 'Error')]
    public function otlpEnumValueIsMappedToItsStatus(int $code, int $expected): void
    {
        $this->tracer()->trace(self::SPAN, static fn(SpanInterface $span) => $span->setStatus($code), traceKind: TraceKind::SERVER);

        Assert::same($this->traces()->await(1)->named(self::SPAN)->statusCode, $expected);
    }

    #[DataSet([500, null, ReceivedSpan::STATUS_ERROR, 'Upstream down'], '500 keeps it')]
    #[DataSet([404, TraceKind::CLIENT, ReceivedSpan::STATUS_ERROR, 'Upstream down'], '404 client keeps it')]
    #[DataSet([404, TraceKind::SERVER, ReceivedSpan::STATUS_UNSET, ''], '404 server drops it')]
    #[DataSet([200, TraceKind::CLIENT, ReceivedSpan::STATUS_UNSET, ''], '200 client drops it')]
    public function integerCodeDescriptionArrivesOnlyWithError(int $code, ?TraceKind $kind, int $expectedCode, string $expectedMessage): void
    {
        $this->tracer()->trace(
            self::SPAN,
            static fn(SpanInterface $span) => $span->setStatus($code, 'Upstream down'),
            traceKind: $kind,
        );

        $span = $this->traces()->await(1)->named(self::SPAN);
        Assert::same($span->statusCode, $expectedCode);
        Assert::same($span->statusMessage, $expectedMessage);
    }

    #[DataSet([['ok', 500], null, ReceivedSpan::STATUS_ERROR], 'ok then 500')]
    #[DataSet([['ok', 404], TraceKind::CLIENT, ReceivedSpan::STATUS_ERROR], 'ok then 404 client')]
    #[DataSet([['error', 200], TraceKind::SERVER, ReceivedSpan::STATUS_UNSET], 'error then 200 server')]
    public function lastIntegerStatusSetInsideCallbackWins(array $codes, ?TraceKind $kind, int $expected): void
    {
        $this->tracer()->trace(self::SPAN, static function (SpanInterface $span) use ($codes): void {
            foreach ($codes as $code) {
                $span->setStatus($code);
            }
        }, traceKind: $kind);

        Assert::same($this->traces()->await(1)->named(self::SPAN)->statusCode, $expected);
    }

    /**
     * The kind that decides 4xx is the span's own: a CLIENT span inside a SERVER request is Error on
     * 404 although the request itself succeeds.
     */
    #[DataSet([404, 200, ReceivedSpan::STATUS_ERROR], 'upstream 404, response 200')]
    #[DataSet([503, 502, ReceivedSpan::STATUS_ERROR], 'upstream 503, response 502')]
    #[DataSet([200, 200, ReceivedSpan::STATUS_UNSET], 'upstream 200, response 200')]
    public function clientSpanInsideRequestUsesItsOwnKindForIntegerCode(int $upstream, int $code, int $expected): void
    {
        $response = $this->fakeHttp()->get('/', query: ['code' => (string) $code, 'upstream' => (string) $upstream]);

        $spans = $this->traces()->await(2);
        $response->assertStatus($code);
        $client = $spans->named('upstream');
        Assert::same($client->kind, ReceivedSpan::KIND_CLIENT);
        Assert::same($client->statusCode, $expected);
        # spiral/http sets Ok below 500, or leaves it Unset as the HTTP conventions say.
        Assert::contains(
            $code < 500 ? [ReceivedSpan::STATUS_OK, ReceivedSpan::STATUS_UNSET] : [ReceivedSpan::STATUS_ERROR],
            $spans->server()->statusCode,
        );
    }
}
