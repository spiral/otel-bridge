<?php

/**
 * Calls a `#[WithSpan]` method inside a Spiral trace `handler`, scoped as `SCENARIO_SCOPED` says. The method
 * and the trace after it each set an attribute through `CurrentSpan`. Run with
 * `-d opentelemetry.attr_hooks_enabled=1`. Written to stdout: `terminated`.
 */

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Process;

use OpenTelemetry\API\Instrumentation\WithSpan;
use Spiral\Boot\Bootloader\ConfigurationBootloader;
use Spiral\Boot\Environment;
use Spiral\Boot\FinalizerInterface;
use Spiral\OpenTelemetry\Bootloader\OpenTelemetryBootloader;
use Spiral\OpenTelemetry\CurrentSpan;
use Spiral\Core\Scope;
use Spiral\Telemetry\TracerInterface;
use Spiral\Testing\TestApp;

\error_reporting(\E_ALL & ~\E_DEPRECATED);

require __DIR__ . '/../../vendor/autoload.php';

final class ScenarioService
{
    #[WithSpan('annotated')]
    public function annotated(): void
    {
        CurrentSpan::get()->setAttribute('in', 'annotated');
    }
}

$app = TestApp::create(directories: ['root' => __DIR__ . '/..'], handleErrors: false)->withBootloaders([
    ConfigurationBootloader::class,
    OpenTelemetryBootloader::class,
])->run(new Environment(['TELEMETRY_DRIVER' => 'otel']));
\assert($app !== null);
$container = $app->getContainer();

$container->runScope(new Scope(), static fn(TracerInterface $tracer): mixed => $tracer->trace('handler', static function (): void {
    (new ScenarioService())->annotated();
    CurrentSpan::get()->setAttribute('in', 'handler');
}, scoped: \getenv('SCENARIO_SCOPED') === '1'));

$container->get(FinalizerInterface::class)->finalize(true);
echo "terminated\n";
