<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance;

use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\ReceivedSpan;
use Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap\SpanList;
use Spiral\OpenTelemetry\Tests\Support\PhpProcess;
use Testo\Assert;
use Testo\Test;

/**
 * The provider and propagator the app shares with `Globals`, where auto-instrumentation takes them from.
 * Each test serves one request in a process of its own with `tests/Process/http-request.php`, with the
 * batch processor, so that only a flush exports.
 */
#[Test]
final class GlobalsTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REMOTE_SPAN_ID = '00f067aa0ba902b7';

    /**
     * The app's provider is the global one until the app terminates; it is flushed at the end of the
     * request and shut down with the app, so a span ended later with the global provider is not exported.
     */
    public function appProviderIsGlobalWhileAppRuns(): void
    {
        $spans = $this->serve([]);

        self::assertRequestFlushedInOneExport($spans);
        Assert::false(\in_array('after terminate', \array_map(static fn(ReceivedSpan $s): string => $s->name, $spans->spans), true));
    }

    /**
     * With `OTEL_PHP_AUTOLOAD_ENABLED` the SDK builds the global provider from the env and the app takes
     * it: flushed at the end of the request, but shut down by the SDK only at process exit.
     */
    public function appTakesGlobalProviderOfSdkAutoloader(): void
    {
        $spans = $this->serve(['OTEL_PHP_AUTOLOAD_ENABLED' => 'true']);

        self::assertRequestFlushedInOneExport($spans);
        Assert::true($spans->named('after terminate')->export > $spans->named('handler')->export);
    }

    private static function assertRequestFlushedInOneExport(SpanList $spans): void
    {
        $server = $spans->named('GET http://localhost/ping');
        $handler = $spans->named('handler');
        Assert::same($server->traceId, self::TRACE_ID, "Received:\n{$spans}");
        Assert::same($server->parentSpanId, self::REMOTE_SPAN_ID);
        Assert::same($handler->traceId, self::TRACE_ID);
        Assert::same($handler->export, $server->export, "Received:\n{$spans}");
        Assert::true($handler->export < $spans->named('between requests')->export, "Received:\n{$spans}");
    }

    /**
     * @param array<string, string> $env
     */
    private function serve(array $env): SpanList
    {
        [$stdout, $stderr, $code] = PhpProcess::run('http-request.php', [
            'OTEL_PHP_TRACES_PROCESSOR' => 'batch',
            'OTEL_BSP_SCHEDULE_DELAY' => '600000',
            'OTEL_METRICS_EXPORTER' => 'none',
            'OTEL_LOGS_EXPORTER' => 'none',
            'SCENARIO_TRACEPARENT' => '00-' . self::TRACE_ID . '-' . self::REMOTE_SPAN_ID . '-01',
            ...$env,
        ]);

        $spans = $this->traces()->settled();
        Assert::same($stderr, '');
        Assert::same($code, 0);
        Assert::same(\array_slice($stdout, 1), ['flushed', 'terminated']);
        Assert::string($stdout[0])->startsWith('response: 00-' . self::TRACE_ID . '-');

        return $spans;
    }
}
