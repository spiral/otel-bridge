<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Internal\Container\Container;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

/**
 * Runs a Buggregator Trap OTLP receiver for the suite and wires every Spiral test of it to export there.
 */
final readonly class TrapPlugin implements PluginConfigurator
{
    #[\Override]
    public function configure(Container $container): void
    {
        $trap = new TrapHandle();
        $interceptors = $container->get(InterceptorCollector::class);
        $interceptors->addInterceptor(new TrapSuiteInterceptor($trap));
        $interceptors->addInterceptor(new OtelEnvInterceptor($trap));
    }
}
