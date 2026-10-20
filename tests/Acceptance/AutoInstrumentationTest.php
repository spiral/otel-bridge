<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Composer\InstalledVersions;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Process\PhpProcess;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanList;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

/**
 * The app with ext-opentelemetry and the PSR-15 and PSR-18 auto-instrumentation, which only the CI job
 * with the extension installs; there `REQUIRE_EXT=1` makes their absence a failure. Each test serves one
 * request in a process of its own with `tests/Process/http-request.php`: hooks cannot be removed once
 * registered, and `Globals` is per process.
 */
#[Test]
final class AutoInstrumentationTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';
    private const PSR15 = 'io.opentelemetry.contrib.php.psr15';
    private const PSR18 = 'io.opentelemetry.contrib.php.psr18';
    private const SERVER = 'GET http://localhost/ping';

    /**
     * The tree is the one without the extension, with the span of the PSR-18 call in it: the Spiral
     * SERVER span under the incoming parent is the root, and psr15 adds nothing.
     */
    public function treeIsTheSameAsWithoutTheExtension(): void
    {
        self::assertTreeWithoutPsr15(...$this->serve());
    }

    /**
     * The alternative the README gives: psr15 switched off altogether.
     */
    public function treeIsTheSameWithPsr15Disabled(): void
    {
        self::assertTreeWithoutPsr15(...$this->serve(['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'psr15']));
    }

    /**
     * The OTLP exporter sends its batches with a PSR-18 client, which the psr18 hook would trace.
     */
    public function exporterRequestsAreNotTraced(): void
    {
        [$spans] = $this->serve();

        $exports = \array_filter(
            $spans->spans,
            static fn(ReceivedSpan $s): bool => \str_contains((string) ($s->attributes['url.full'] ?? ''), '/v1/traces'),
        );
        Assert::same($exports, [], "Received:\n{$spans}");
    }

    /**
     * The SDK autoloader configures the global pipeline and the app takes it: the spans are one trace,
     * flushed at the end of the request. The SDK builds the provider, so nothing is suppressed.
     */
    public function withSdkAutoloaderHookSpansAndSpiralSpansAreFlushedTogether(): void
    {
        [$spans, $response] = $this->serve([
            'OTEL_PHP_AUTOLOAD_ENABLED' => 'true',
            'OTEL_METRICS_EXPORTER' => 'none',
            'OTEL_LOGS_EXPORTER' => 'none',
        ]);

        $request = self::request($spans);
        $server = self::single($request, self::PSR15, ReceivedSpan::KIND_SERVER, 'GET');
        $handler = $request->named('handler');
        $client = self::single($request, self::PSR18, ReceivedSpan::KIND_CLIENT);
        Assert::same($server->parentSpanId, self::REMOTE_SPAN_ID, "Received:\n{$spans}");
        Assert::same($client->parentSpanId, $handler->spanId);
        self::assertOneTraceInOneFlush($spans);
        # Ended after the app terminated, with the provider the SDK shuts down only at exit.
        Assert::true($spans->named('after terminate')->export > $handler->export);
        Assert::string($response)->startsWith('00-' . self::TRACE_ID . '-');
    }

    private static function assertTreeWithoutPsr15(SpanList $spans, string $response): void
    {
        $request = self::request($spans);
        $servers = \array_values(\array_filter($request->spans, static fn(ReceivedSpan $s): bool => $s->kind === ReceivedSpan::KIND_SERVER));
        $psr15 = \array_filter($request->spans, static fn(ReceivedSpan $s): bool => $s->scopeName === self::PSR15);
        Assert::count($servers, 1, "Received:\n{$spans}");
        Assert::same($psr15, [], "Received:\n{$spans}");

        $server = $servers[0];
        Assert::same($server->name, self::SERVER, "Received:\n{$spans}");
        Assert::same($server->parentSpanId, self::REMOTE_SPAN_ID);
        # https://opentelemetry.io/docs/specs/otlp/: bit 8 says whether the parent is known to be remote, bit 9 that it is.
        Assert::same($server->flags & 0x300, 0x300, "Received:\n{$spans}");
        Assert::same(
            self::single($request, self::PSR18, ReceivedSpan::KIND_CLIENT)->parentSpanId,
            $request->named('handler')->spanId,
        );

        $ids = \array_map(static fn(ReceivedSpan $s): string => $s->spanId, $request->spans);
        foreach ($request as $span) {
            $span === $server or Assert::contains($ids, (string) $span->parentSpanId, "Received:\n{$spans}");
        }
        self::assertOneTraceInOneFlush($spans);
        Assert::same($response, '00-' . self::TRACE_ID . "-{$server->spanId}-01");
    }

    /**
     * All the spans of the request are one trace and go out in one export, before the span ended after it.
     */
    private static function assertOneTraceInOneFlush(SpanList $spans): void
    {
        $request = self::request($spans);
        $export = $request->named('handler')->export;
        foreach ($request as $span) {
            Assert::same($span->traceId, self::TRACE_ID, "Received:\n{$spans}");
            Assert::same($span->export, $export, "Received:\n{$spans}");
        }
        Assert::true($export < $spans->named('between requests')->export, "Received:\n{$spans}");
    }

    /**
     * The spans of the request, without those the scenario ends after it.
     */
    private static function request(SpanList $spans): SpanList
    {
        return new SpanList(\array_values(\array_filter(
            $spans->spans,
            static fn(ReceivedSpan $s): bool => $s->scopeName !== 'scenario',
        )));
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{SpanList, string} The spans and the `traceparent` of the response.
     */
    private function serve(array $env = []): array
    {
        $installed = \extension_loaded('opentelemetry')
            && InstalledVersions::isInstalled('open-telemetry/opentelemetry-auto-psr15')
            && InstalledVersions::isInstalled('open-telemetry/opentelemetry-auto-psr18');
        if (!$installed) {
            \getenv('REQUIRE_EXT') === '1'
                ? Assert::fail('ext-opentelemetry or the PSR-15 and PSR-18 auto-instrumentation is missing.')
                : throw new SkipTest('ext-opentelemetry or the PSR-15 and PSR-18 auto-instrumentation is not installed.');
        }

        [$stdout, $stderr, $code] = PhpProcess::run('http-request.php', [
            'OTEL_PHP_TRACES_PROCESSOR' => 'batch',
            'OTEL_BSP_SCHEDULE_DELAY' => '600000',
            'SCENARIO_TRACEPARENT' => '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01',
            ...$env,
        ]);

        $spans = $this->traces()->settled();
        Assert::same($stderr, '');
        Assert::same($code, 0);
        Assert::same(\array_slice($stdout, 1), ['flushed', 'terminated']);

        return [$spans, \substr($stdout[0], \strlen('response: '))];
    }

    /**
     * The only span of the instrumentation scope and kind, and name if given.
     */
    private static function single(SpanList $spans, string $scope, int $kind, ?string $name = null): ReceivedSpan
    {
        $found = \array_values(\array_filter(
            $spans->spans,
            static fn(ReceivedSpan $s): bool => $s->scopeName === $scope && $s->kind === $kind
                && ($name === null || $s->name === $name),
        ));
        Assert::count($found, 1, "Received:\n{$spans}");

        return $found[0];
    }
}
