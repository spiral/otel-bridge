<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit\Config;

use Spiral\OpenTelemetry\Config\OpenTelemetryConfig;
use Spiral\OpenTelemetry\Config\ProviderMode;
use Spiral\OpenTelemetry\Exception\InvalidConfigException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(OpenTelemetryConfig::class)]
final class OpenTelemetryConfigTest
{
    public function defaults(): void
    {
        $config = new OpenTelemetryConfig();

        Assert::same($config->getMode(), ProviderMode::Auto);
        Assert::true($config->suppressPsr15());
        Assert::same($config->getFlushEvery(), 1);
        Assert::null($config->getFlushInterval());
    }

    #[DataSet(['auto', ProviderMode::Auto])]
    #[DataSet(['bridge', ProviderMode::Bridge])]
    #[DataSet(['globals', ProviderMode::Globals])]
    #[DataSet([ProviderMode::Globals, ProviderMode::Globals], 'enum')]
    public function modeIsReadFromStringOrEnum(string|ProviderMode $value, ProviderMode $mode): void
    {
        $config = new OpenTelemetryConfig(['mode' => $value]);

        Assert::same($config->getMode(), $mode);
    }

    /**
     * Keys of `flush` left out keep their defaults.
     */
    #[DataSet([['every' => 0], 0, null], 'every only')]
    #[DataSet([['interval' => 2.5], 1, 2.5], 'interval only')]
    #[DataSet([['every' => 10, 'interval' => 30], 10, 30], 'both')]
    public function flushIsRead(array $flush, int $every, int|float|null $interval): void
    {
        $config = new OpenTelemetryConfig(['flush' => $flush]);

        Assert::same($config->getFlushEvery(), $every);
        Assert::same($config->getFlushInterval(), $interval);
    }

    public function suppressionCanBeDisabled(): void
    {
        $config = new OpenTelemetryConfig(['suppress_psr15' => false]);

        Assert::false($config->suppressPsr15());
    }

    #[DataSet([['mode' => 'Bridge'], "Invalid `opentelemetry.mode` 'Bridge', expected one of: auto, bridge, globals."], 'unknown mode')]
    #[DataSet([['mode' => null], 'Invalid `opentelemetry.mode` of type null, expected one of: auto, bridge, globals.'], 'mode not a string')]
    #[DataSet([['suppress_psr15' => 1], 'Invalid `opentelemetry.suppress_psr15` 1, expected a bool.'], 'suppression not a bool')]
    #[DataSet([['flush' => 5], 'Invalid `opentelemetry.flush` 5, expected an array with the keys `every` and `interval`.'], 'flush not an array')]
    #[DataSet([['flush' => ['every' => -1]], 'Invalid `opentelemetry.flush.every` -1, expected an integer from 0.'], 'negative count')]
    #[DataSet([['flush' => ['every' => '2']], "Invalid `opentelemetry.flush.every` '2', expected an integer from 0."], 'count not an int')]
    #[DataSet([['flush' => ['interval' => 0]], 'Invalid `opentelemetry.flush.interval` 0, expected a positive number of seconds or null.'], 'zero interval')]
    #[DataSet([['flush' => ['interval' => -0.5]], 'Invalid `opentelemetry.flush.interval` -0.5, expected a positive number of seconds or null.'], 'negative interval')]
    #[DataSet([['flush' => ['interval' => '10']], "Invalid `opentelemetry.flush.interval` '10', expected a positive number of seconds or null."], 'interval not a number')]
    public function invalidValueIsRejected(array $config, string $message): never
    {
        Expect::exception(InvalidConfigException::class)->withMessage($message);

        new OpenTelemetryConfig($config);
    }
}
