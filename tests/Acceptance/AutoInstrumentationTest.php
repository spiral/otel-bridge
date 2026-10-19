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
 * with the extension installs. Each test serves one request in a process of its own with
 * `tests/Process/http-request.php`: hooks cannot be removed once registered, and `Globals` is per process.
 */
#[Test]
final class AutoInstrumentationTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';
    private const PSR15 = 'io.opentelemetry.contrib.php.psr15';
    private const PSR18 = 'io.opentelemetry.contrib.php.psr18';

    /**
     * The psr15 SERVER span is the root of the request under the incoming parent, the Spiral one is
     * suppressed inside it, and the Spiral spans and the hook span of the PSR-18 call nest under it.
     */
    public function hookSpansAndSpiralSpansFormOneTree(): void
    {
        [$spans, $response] = $this->serve();

        $server = self::single($spans, self::PSR15, ReceivedSpan::KIND_SERVER);
        $handler = $spans->named('handler');
        $client = self::single($spans, self::PSR18, ReceivedSpan::KIND_CLIENT);
        Assert::same($server->traceId, self::TRACE_ID, "Received:\n{$spans}");
        Assert::same($server->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($client->parentSpanId, $handler->spanId);
        foreach ($spans as $span) {
            Assert::same($span->traceId, self::TRACE_ID, "Received:\n{$spans}");
        }
        Assert::same($response, '00-' . self::TRACE_ID . "-{$server->spanId}-01");
    }

    public function spansDoubledByPsr15AreSuppressed(): void
    {
        [$spans] = $this->serve();

        $psr15 = \array_filter($spans->spans, static fn(ReceivedSpan $s): bool => $s->scopeName === self::PSR15);
        $spiralServer = \array_filter($spans->spans, static fn(ReceivedSpan $s): bool => $s->scopeName === 'spiral/otel-bridge'
            && $s->kind === ReceivedSpan::KIND_SERVER);
        Assert::count($psr15, 1, "Received:\n{$spans}");
        Assert::same($spiralServer, [], "Received:\n{$spans}");
    }

    /**
     * One pipeline: the end of the request flushes the spans of hooks with the Spiral ones.
     */
    public function hookSpansAreExportedInTheFlushOfTheRequest(): void
    {
        [$spans] = $this->serve();

        $export = $spans->named('handler')->export;
        foreach ($spans as $span) {
            Assert::same($span->export, $export, "Received:\n{$spans}");
        }
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

        $server = self::single($spans, self::PSR15, ReceivedSpan::KIND_SERVER, 'GET');
        $handler = $spans->named('handler');
        $client = self::single($spans, self::PSR18, ReceivedSpan::KIND_CLIENT);
        Assert::same($server->parentSpanId, self::REMOTE_SPAN_ID, "Received:\n{$spans}");
        Assert::same($client->parentSpanId, $handler->spanId);
        Assert::same($client->export, $handler->export);
        Assert::same($server->export, $handler->export);
        # Ended after the app terminated, with the provider the SDK shuts down only at exit.
        Assert::true($spans->named('after terminate')->export > $handler->export);
        foreach ($spans as $span) {
            if ($span->name === 'after terminate') {
                continue;
            }
            Assert::same($span->traceId, self::TRACE_ID, "Received:\n{$spans}");
            Assert::false(\str_contains((string) ($span->attributes['url.full'] ?? ''), '/v1/traces'), "Received:\n{$spans}");
        }
        Assert::string($response)->startsWith('00-' . self::TRACE_ID . '-');
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{SpanList, string} The spans of the request and the `traceparent` of the response.
     */
    private function serve(array $env = []): array
    {
        \extension_loaded('opentelemetry') or throw new SkipTest('ext-opentelemetry is not loaded.');
        InstalledVersions::isInstalled('open-telemetry/opentelemetry-auto-psr15')
            && InstalledVersions::isInstalled('open-telemetry/opentelemetry-auto-psr18')
            or throw new SkipTest('The PSR-15 and PSR-18 auto-instrumentation is not installed.');

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
