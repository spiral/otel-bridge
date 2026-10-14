<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Acceptance\Support\Trap;

use Spiral\Boot\EnvironmentInterface;
use Spiral\Core\Container;
use Spiral\Testing\AppContext;
use Spiral\Testing\Attribute\Env;
use Spiral\Testing\Stage;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Value\TestType;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Points the application of each test at Trap and hands the OTEL_* env of the app to the OpenTelemetry SDK.
 *
 * The SDK reads its settings from `getenv()` / `$_SERVER`, not from Spiral's {@see EnvironmentInterface},
 * so the `OTEL_*` values the app ends up with (`ENV` of the test case, `#[Env]`, the endpoint and a
 * per-test service name set here) are copied into the process env before the app boots and restored
 * once the test is over. The test then finds a {@see SpanCollector} for its service name in the container.
 */
#[InterceptorOptions(
    # After #[Env] and the other configure-stage attributes, before the app boots.
    order: Stage::CONFIGURE + 200,
    testType: TestType::Test,
)]
final readonly class OtelEnvInterceptor implements TestRunInterceptor
{
    public function __construct(
        private TrapHandle $trap,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $context = $info->getAttribute(AppContext::class);
        if (!$context instanceof AppContext) {
            return $next($info);
        }

        $server = $this->trap->server();
        $declared = self::declaredEnv($info, $context);
        # Not EnvironmentInterface::set(): it writes $_ENV and the process env, which outlive the test.
        $context->addEnv(\array_diff_key([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => $server->endpoint(),
            'OTEL_SERVICE_NAME' => 'otel-bridge-acceptance-' . \bin2hex(\random_bytes(6)),
        ], $declared));

        $previous = [];
        $context->beforeInit(static function (EnvironmentInterface $env, Container $container) use ($server, &$previous): void {
            foreach ($env->getAll() as $key => $value) {
                if (\str_starts_with((string) $key, 'OTEL_') && \is_scalar($value)) {
                    $previous[$key] ??= \getenv((string) $key);
                    \putenv("{$key}=" . self::stringify($value));
                }
            }

            $container->bindSingleton(SpanCollector::class, new SpanCollector(
                $server->dumpDirectory(),
                (string) $env->get('OTEL_SERVICE_NAME'),
            ));
        });

        try {
            return $next($info);
        } finally {
            foreach ($previous as $key => $value) {
                \putenv($value === false ? (string) $key : "{$key}={$value}");
            }
        }
    }

    /**
     * Keys the test declares itself, through `ENV` of the test case or `#[Env]`.
     *
     * @return array<string, mixed>
     */
    private static function declaredEnv(TestInfo $info, AppContext $context): array
    {
        $declared = $context->testCase::ENV;
        /** @var list<Env> $attributes */
        $attributes = $info->getAttribute(Env::class, []);
        foreach ($attributes as $attribute) {
            $declared[$attribute->key] = $attribute->value;
        }

        return $declared;
    }

    private static function stringify(bool|int|float|string $value): string
    {
        return \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
