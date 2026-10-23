<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Config;

use Spiral\Core\InjectableConfig;
use Spiral\OpenTelemetry\Exception\InvalidConfigException;

/**
 * Settings of the bridge itself, `config/opentelemetry.php`; the SDK is configured with the `OTEL_*`
 * variables. Validated when built, so a wrong value fails the boot.
 */
final class OpenTelemetryConfig extends InjectableConfig
{
    public const CONFIG = 'opentelemetry';

    protected array $config = [
        'mode' => ProviderMode::Auto,
        'suppress_psr15' => true,
        'flush' => [
            'every' => 1,
            'interval' => null,
        ],
    ];

    /**
     * @throws InvalidConfigException
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->getMode();
        $this->suppressPsr15();
        $this->getFlushEvery();
        $this->getFlushInterval();
    }

    public function getMode(): ProviderMode
    {
        /** @var mixed $mode */
        $mode = $this->config['mode'];
        if ($mode instanceof ProviderMode) {
            return $mode;
        }

        return (\is_string($mode) ? ProviderMode::tryFrom($mode) : null) ?? throw new InvalidConfigException(\sprintf(
            'Invalid `%s.mode` %s, expected one of: %s.',
            self::CONFIG,
            self::export($mode),
            \implode(', ', \array_map(static fn(ProviderMode $m): string => $m->value, ProviderMode::cases())),
        ));
    }

    /**
     * Whether the spans of `opentelemetry-auto-psr15` that double Spiral ones are suppressed.
     */
    public function suppressPsr15(): bool
    {
        /** @var mixed $value */
        $value = $this->config['suppress_psr15'];

        return \is_bool($value) ? $value : throw new InvalidConfigException(\sprintf(
            'Invalid `%s.suppress_psr15` %s, expected a bool.',
            self::CONFIG,
            self::export($value),
        ));
    }

    /**
     * After every how many requests or jobs the provider is flushed; 0 is only on shutdown.
     *
     * @return int<0, max>
     */
    public function getFlushEvery(): int
    {
        /** @var mixed $value */
        $value = $this->flush()['every'] ?? 1;

        return \is_int($value) && $value >= 0 ? $value : throw new InvalidConfigException(\sprintf(
            'Invalid `%s.flush.every` %s, expected an integer from 0.',
            self::CONFIG,
            self::export($value),
        ));
    }

    /**
     * Seconds since the last flush after which the end of a request or job flushes the provider whatever
     * the count; null when only the count matters.
     */
    public function getFlushInterval(): int|float|null
    {
        /** @var mixed $value */
        $value = $this->flush()['interval'] ?? null;

        if ($value === null) {
            return null;
        }

        if ((\is_int($value) || \is_float($value)) && $value > 0) {
            return $value;
        }

        throw new InvalidConfigException(\sprintf(
            'Invalid `%s.flush.interval` %s, expected a positive number of seconds or null.',
            self::CONFIG,
            self::export($value),
        ));
    }

    private static function export(mixed $value): string
    {
        return \is_scalar($value) ? \var_export($value, true) : 'of type ' . \get_debug_type($value);
    }

    /**
     * A key left out keeps its default: the defaults of a section are merged with the app's at the top only.
     */
    private function flush(): array
    {
        /** @var mixed $flush */
        $flush = $this->config['flush'];

        return \is_array($flush) ? $flush : throw new InvalidConfigException(\sprintf(
            'Invalid `%s.flush` %s, expected an array with the keys `every` and `interval`.',
            self::CONFIG,
            self::export($flush),
        ));
    }
}
