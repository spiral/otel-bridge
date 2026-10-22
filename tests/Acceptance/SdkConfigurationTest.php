<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Composer\InstalledVersions;
use OpenTelemetry\API\Behavior\Internal\Logging;
use Spiral\Boot\EnvironmentInterface;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\SdkLogWriter;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\OpenTelemetry\Tests\Support\TestEnv;
use Spiral\Telemetry\SpanInterface;
use Spiral\Telemetry\TraceKind;
use Spiral\Telemetry\TracerInterface;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The SDK settings an app configures through `OTEL_*` env, as the collector sees their effect:
 * the resource, the sampler and the OTLP/HTTP transport.
 *
 * https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/
 */
#[Test]
final class SdkConfigurationTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';
    private const SAMPLED_TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01';
    private const UNSAMPLED_TRACEPARENT = '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-00';
    private const UNREACHABLE = 'http://127.0.0.1:1';

    /** 2024-02-29T13:14:15.123456789Z: survives both encodings only if all 64 bits of nanoseconds do. */
    private const START_TIME = 1_709_212_455_123_456_789;

    private SdkLogWriter $log;

    #[BeforeTest]
    public function captureSdkLog(): void
    {
        $this->log = new SdkLogWriter();
        Logging::setLogWriter($this->log);
    }

    #[AfterTest]
    public function restoreSdkLog(): void
    {
        Logging::reset();
    }

    /**
     * The SDK always provides `telemetry.sdk.*`, and `service.name` comes from `OTEL_SERVICE_NAME`.
     * https://opentelemetry.io/docs/specs/semconv/resource/#telemetry-sdk
     */
    public function resourceDescribesServiceAndSdk(): void
    {
        $this->tracer()->trace('resource', static fn() => null);

        $resource = $this->traces()->await(1)->named('resource')->resourceAttributes;
        Assert::same($resource['service.name'] ?? null, $this->env('OTEL_SERVICE_NAME'));
        Assert::same($resource['telemetry.sdk.name'] ?? null, 'opentelemetry');
        Assert::same($resource['telemetry.sdk.language'] ?? null, 'php');
        Assert::same($resource['telemetry.sdk.version'] ?? null, InstalledVersions::getPrettyVersion('open-telemetry/sdk'));
    }

    /**
     * "If `service.name` is also provided in `OTEL_RESOURCE_ATTRIBUTES`, then `OTEL_SERVICE_NAME` takes precedence."
     * https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/#general-sdk-configuration
     */
    #[Env('OTEL_RESOURCE_ATTRIBUTES', 'service.name=sdk-configuration-overridden,service.version=1.2.3,deployment.environment.name=staging')]
    public function serviceNameTakesPrecedenceOverResourceAttributes(): void
    {
        $this->tracer()->trace('resource', static fn() => null);

        $resource = $this->traces()->await(1)->named('resource')->resourceAttributes;
        Assert::same($resource['service.name'] ?? null, $this->env('OTEL_SERVICE_NAME'));
        Assert::same($resource['service.version'] ?? null, '1.2.3');
        Assert::same($resource['deployment.environment.name'] ?? null, 'staging');
    }

    /**
     * Without `OTEL_SERVICE_NAME` the resource takes `service.name` from `OTEL_RESOURCE_ATTRIBUTES`.
     * The harness always sets `OTEL_SERVICE_NAME`, so it is taken out of the process env before the
     * tracer provider is built; the collector still looks for the same name.
     */
    public function serviceNameFallsBackToResourceAttributes(): void
    {
        $name = $this->env('OTEL_SERVICE_NAME');

        TestEnv::withProcessEnv(
            ['OTEL_SERVICE_NAME' => null, 'OTEL_RESOURCE_ATTRIBUTES' => "service.name={$name},service.version=2.0.0"],
            fn() => $this->tracer()->trace('resource', static fn() => null),
        );

        $resource = $this->traces()->await(1)->named('resource')->resourceAttributes;
        Assert::same($resource['service.name'] ?? null, $name);
        Assert::same($resource['service.version'] ?? null, '2.0.0');
    }

    /**
     * `,` and `=` inside values are percent-encoded, and every value is percent-decoded.
     * https://opentelemetry.io/docs/specs/otel/resource/sdk/#specifying-resource-information-via-an-environment-variable
     */
    #[Env(
        'OTEL_RESOURCE_ATTRIBUTES',
        'sdkconfig.comma=a%2Cb,sdkconfig.equals=k%3Dv,sdkconfig.space=hello%20world,sdkconfig.percent=100%25,'
        . 'sdkconfig.unicode=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82,sdkconfig.plain=as-is',
    )]
    public function resourceAttributeValuesArePercentDecoded(): void
    {
        $this->tracer()->trace('resource', static fn() => null);

        $resource = $this->traces()->await(1)->named('resource')->resourceAttributes;
        $own = \array_filter($resource, static fn(string $key): bool => \str_starts_with($key, 'sdkconfig.'), \ARRAY_FILTER_USE_KEY);
        \ksort($own);
        Assert::same($own, [
            'sdkconfig.comma' => 'a,b',
            'sdkconfig.equals' => 'k=v',
            'sdkconfig.percent' => '100%',
            'sdkconfig.plain' => 'as-is',
            'sdkconfig.space' => 'hello world',
            'sdkconfig.unicode' => 'привет',
        ]);
    }

    /**
     * Every span the Spiral tracer makes comes from one instrumentation scope, the bridge package with its
     * version: https://opentelemetry.io/docs/specs/otel/common/instrumentation-scope/
     */
    public function spansOfOneAppShareOneInstrumentationScope(): void
    {
        $tracer = $this->tracer();

        $tracer->trace('outer', static fn() => $tracer->trace('inner', static fn() => null, scoped: true), scoped: true);
        $this->tracerWithContext(['traceparent' => self::SAMPLED_TRACEPARENT])->trace('continued', static fn() => null);

        $spans = $this->traces()->await(3);
        $scopes = \array_unique(\array_map(
            static fn(ReceivedSpan $s): string => "{$s->scopeName}@{$s->scopeVersion}",
            $spans->spans,
        ));
        $version = \json_decode((string) \file_get_contents(__DIR__ . '/../../resources/version.json'), true)['.'];
        Assert::same($scopes, ["spiral/otel-bridge@{$version}"]);
    }

    /**
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#alwayson
     */
    #[Env('OTEL_TRACES_SAMPLER', 'always_on')]
    public function alwaysOnSamplerExportsEveryRootSpan(): void
    {
        $this->traceRoots(5);

        $this->assertRootsExported(5);
    }

    /**
     * AlwaysOn ignores the parent's decision, so an unsampled remote parent does not stop the span.
     */
    #[Env('OTEL_TRACES_SAMPLER', 'always_on')]
    public function alwaysOnSamplerSamplesUnderUnsampledRemoteParent(): void
    {
        $this->tracerWithContext(['traceparent' => self::UNSAMPLED_TRACEPARENT])->trace('continued', static fn() => null);

        $span = $this->traces()->await(1)->named('continued');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($span->flags & 0xFF, 0x01);
    }

    /**
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#alwaysoff
     */
    #[Env('OTEL_TRACES_SAMPLER', 'always_off')]
    public function alwaysOffSamplerExportsNothing(): void
    {
        $this->traceRoots(5);

        $this->assertNothingArrives();
    }

    /**
     * A ratio of 1 samples every trace id.
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#traceidratiobased
     */
    #[Env('OTEL_TRACES_SAMPLER', 'traceidratio')]
    #[Env('OTEL_TRACES_SAMPLER_ARG', '1')]
    public function traceIdRatioOfOneExportsEveryTrace(): void
    {
        $this->traceRoots(20);

        $this->assertRootsExported(20);
    }

    #[Env('OTEL_TRACES_SAMPLER', 'traceidratio')]
    #[Env('OTEL_TRACES_SAMPLER_ARG', '0')]
    public function traceIdRatioOfZeroExportsNothing(): void
    {
        $this->traceRoots(20);

        $this->assertNothingArrives();
    }

    /**
     * https://opentelemetry.io/docs/specs/otel/trace/sdk/#parentbased
     */
    #[Env('OTEL_TRACES_SAMPLER', 'parentbased_always_on')]
    public function parentBasedAlwaysOnFollowsSampledRemoteParent(): void
    {
        $this->tracerWithContext(['traceparent' => self::SAMPLED_TRACEPARENT])->trace('continued', static fn() => null);

        $span = $this->traces()->await(1)->named('continued');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
    }

    #[Env('OTEL_TRACES_SAMPLER', 'parentbased_always_on')]
    public function parentBasedAlwaysOnDropsUnsampledRemoteParent(): void
    {
        $this->tracerWithContext(['traceparent' => self::UNSAMPLED_TRACEPARENT])->trace('continued', static fn() => null);
        $this->tracer()->trace('marker', static fn() => null);

        $spans = $this->traces()->await(1);
        Assert::count($spans, 1, "Received:\n{$spans}");
        Assert::notSame($spans->named('marker')->traceId, self::TRACE_ID);
    }

    /**
     * The root falls to the delegate (AlwaysOff); a sampled remote parent still makes the span sampled.
     */
    #[Env('OTEL_TRACES_SAMPLER', 'parentbased_always_off')]
    public function parentBasedAlwaysOffFollowsSampledRemoteParent(): void
    {
        $this->tracerWithContext(['traceparent' => self::SAMPLED_TRACEPARENT])->trace('continued', static fn() => null);

        $span = $this->traces()->await(1)->named('continued');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
    }

    #[Env('OTEL_TRACES_SAMPLER', 'parentbased_always_off')]
    public function parentBasedAlwaysOffDropsRootSpans(): void
    {
        $this->traceRoots(5);

        $this->assertNothingArrives();
    }

    #[Env('OTEL_TRACES_SAMPLER', 'parentbased_traceidratio')]
    #[Env('OTEL_TRACES_SAMPLER_ARG', '0')]
    public function parentBasedRatioOfZeroDropsRootSpans(): void
    {
        $this->traceRoots(5);

        $this->assertNothingArrives();
    }

    #[Env('OTEL_TRACES_SAMPLER', 'parentbased_traceidratio')]
    #[Env('OTEL_TRACES_SAMPLER_ARG', '0')]
    public function parentBasedRatioOfZeroFollowsSampledRemoteParent(): void
    {
        $this->tracerWithContext(['traceparent' => self::SAMPLED_TRACEPARENT])->trace('continued', static fn() => null);

        $span = $this->traces()->await(1)->named('continued');
        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->parentSpanId, self::REMOTE_SPAN_ID);
    }

    public function jsonTransportDeliversTheSpanModel(): void
    {
        $this->assertSpanModelDelivered();
    }

    /**
     * Protobuf carries the same spans as JSON: ids as bytes, times as fixed64, typed attribute values.
     * https://opentelemetry.io/docs/specs/otlp/#otlphttp
     */
    #[Env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')]
    public function protobufTransportDeliversTheSameSpanModel(): void
    {
        $this->assertSpanModelDelivered();
    }

    /**
     * `gzip` is the only compression the spec defines.
     * https://opentelemetry.io/docs/specs/otel/protocol/exporter/#configuration-options
     */
    #[Env('OTEL_EXPORTER_OTLP_COMPRESSION', 'gzip')]
    public function gzipCompressedJsonDeliversTheSameSpanModel(): void
    {
        $this->assertSpanModelDelivered();
    }

    #[Env('OTEL_EXPORTER_OTLP_COMPRESSION', 'gzip')]
    #[Env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')]
    public function gzipCompressedProtobufDeliversTheSameSpanModel(): void
    {
        $this->assertSpanModelDelivered();
    }

    /**
     * Headers are `key=value` pairs in the W3C Baggage format, values percent-decoded.
     * https://opentelemetry.io/docs/specs/otel/protocol/exporter/#specifying-headers-via-environment-variables
     */
    #[Env('OTEL_EXPORTER_OTLP_HEADERS', 'x-api-key=secret,x-tenant=acme%20corp')]
    public function customHeadersDoNotBreakExport(): void
    {
        $this->tracer()->trace('with-headers', static fn() => null);

        Assert::count($this->traces()->await(1), 1);
        $this->assertNoProblems();
    }

    /**
     * The signal-specific endpoint wins over the generic one.
     * https://opentelemetry.io/docs/specs/otel/protocol/exporter/#endpoint-urls-for-otlphttp
     */
    public function signalSpecificEndpointTakesPrecedence(): void
    {
        $base = $this->env('OTEL_EXPORTER_OTLP_ENDPOINT');

        TestEnv::withProcessEnv(
            ['OTEL_EXPORTER_OTLP_ENDPOINT' => self::UNREACHABLE, 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => "{$base}/v1/traces"],
            fn() => $this->tracer()->trace('signal-endpoint', static fn() => null),
        );

        Assert::count($this->traces()->await(1), 1);
        $this->assertNoProblems();
    }

    /**
     * "For the per-signal variables the URL MUST be used as-is": a base URL there gets no `v1/traces`,
     * so the request lands on `/`, which Trap does not take for OTLP.
     * https://opentelemetry.io/docs/specs/otel/protocol/exporter/#endpoint-urls-for-otlphttp
     */
    public function signalSpecificEndpointIsUsedAsIs(): void
    {
        $base = $this->env('OTEL_EXPORTER_OTLP_ENDPOINT');

        TestEnv::withProcessEnv(
            ['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => $base],
            fn() => $this->tracer()->trace('as-is', static fn() => null),
        );

        $this->assertNothingArrives();
    }

    /**
     * A base URL with a trailing slash still reaches the traces path: it is kept as the base path and
     * `v1/traces` is appended. Trap matches the path by its `/v1/traces` suffix, so a doubled slash would pass too.
     * https://opentelemetry.io/docs/specs/otel/protocol/exporter/#endpoint-urls-for-otlphttp (example 3)
     */
    public function genericEndpointWithTrailingSlashGetsTracesPath(): void
    {
        $base = $this->env('OTEL_EXPORTER_OTLP_ENDPOINT');

        TestEnv::withProcessEnv(
            ['OTEL_EXPORTER_OTLP_ENDPOINT' => "{$base}/"],
            fn() => $this->tracer()->trace('trailing-slash', static fn() => null),
        );

        Assert::count($this->traces()->await(1), 1);
        $this->assertNoProblems();
    }

    private function env(string $key): string
    {
        return (string) $this->getContainer()->get(EnvironmentInterface::class)->get($key);
    }

    private function traceRoots(int $count): void
    {
        $tracer = $this->tracer();
        for ($i = 0; $i < $count; ++$i) {
            $tracer->trace("root-{$i}", static fn() => null);
        }
    }

    private function assertRootsExported(int $count): void
    {
        $spans = $this->traces()->await($count);
        Assert::count($spans, $count, "Received:\n{$spans}");
        for ($i = 0; $i < $count; ++$i) {
            $span = $spans->named("root-{$i}");
            Assert::true($span->isRoot());
            Assert::same($span->flags & 0xFF, 0x01, "root-{$i} is not sampled");
        }
    }

    /**
     * A tree with every attribute type, kinds, a status with a message, an exception event and an exact
     * start time, checked field by field; the protocols must all pass the same check.
     */
    private function assertSpanModelDelivered(): void
    {
        $this->tracer()->trace(
            'model.root',
            static function (SpanInterface $span, TracerInterface $tracer): void {
                $span->setAttributes([
                    'string' => 'text',
                    'int' => -42,
                    'float' => 1.5,
                    'bool' => false,
                    'strings' => ['a', 'b'],
                    'ints' => [1, 2, 3],
                ]);
                $tracer->trace('model.child', static fn() => null, ['child' => 'yes'], scoped: true, traceKind: TraceKind::CLIENT);
                try {
                    $tracer->trace('model.failing', static fn() => throw new \DomainException('boom'), traceKind: TraceKind::PRODUCER);
                } catch (\DomainException) {
                }
                $span->setStatus('error', 'root failed');
            },
            scoped: true,
            traceKind: TraceKind::SERVER,
            startTime: self::START_TIME,
        );

        $spans = $this->traces()->await(3);
        $root = $spans->named('model.root');
        $child = $spans->named('model.child');
        $failing = $spans->named('model.failing');
        Assert::count($spans, 3, "Received:\n{$spans}");
        Assert::same($root->startTimeUnixNano, self::START_TIME);
        Assert::true($root->isRoot());
        Assert::string($root->traceId)->matchesRegex('/^[0-9a-f]{32}$/');
        Assert::string($root->spanId)->matchesRegex('/^[0-9a-f]{16}$/');
        Assert::same([$child->traceId, $failing->traceId], [$root->traceId, $root->traceId]);
        Assert::same([$child->parentSpanId, $failing->parentSpanId], [$root->spanId, $root->spanId]);
        Assert::same(
            [$root->kind, $child->kind, $failing->kind],
            [ReceivedSpan::KIND_SERVER, ReceivedSpan::KIND_CLIENT, ReceivedSpan::KIND_PRODUCER],
        );
        Assert::same($root->attributes, [
            'string' => 'text',
            'int' => -42,
            'float' => 1.5,
            'bool' => false,
            'strings' => ['a', 'b'],
            'ints' => [1, 2, 3],
        ]);
        Assert::same($child->attributes, ['child' => 'yes']);
        Assert::same([$root->statusCode, $root->statusMessage], [ReceivedSpan::STATUS_ERROR, 'root failed']);
        Assert::same([$root->events, $child->events], [[], []]);
        Assert::count($failing->events, 1);
        Assert::same($failing->events[0]['name'], 'exception');
        Assert::same($failing->events[0]['attributes']['exception.type'] ?? null, \DomainException::class);
        Assert::same($failing->events[0]['attributes']['exception.message'] ?? null, 'boom');
        Assert::int($failing->events[0]['timeUnixNano'])->greaterThanOrEqual($failing->startTimeUnixNano);
        $this->assertNoProblems();
    }

    private function assertNothingArrives(): void
    {
        $spans = $this->traces()->settled();
        Assert::count($spans, 0, "Received:\n{$spans}");
    }

    private function assertNoProblems(): void
    {
        $problems = $this->log->problems();
        Assert::same($problems, [], "The SDK reported:\n" . \implode("\n", $problems));
    }
}
