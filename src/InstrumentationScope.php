<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry;

/**
 * The instrumentation scope of the spans the bridge creates.
 *
 * @internal
 */
final class InstrumentationScope
{
    public const NAME = 'spiral/otel-bridge';

    /**
     * The release-please manifest, shipped with the package.
     */
    private const MANIFEST = __DIR__ . '/../resources/version.json';

    private static bool $resolved = false;
    private static ?string $version = null;

    /**
     * The package version, read from the release manifest on the first call.
     */
    public static function version(): ?string
    {
        if (!self::$resolved) {
            self::$version = self::readVersion(self::MANIFEST);
            self::$resolved = true;
        }

        return self::$version;
    }

    /**
     * The version in a release manifest, or null when the file is missing or broken.
     */
    public static function readVersion(string $manifest): ?string
    {
        $json = \is_file($manifest) && \is_readable($manifest) ? \file_get_contents($manifest) : false;
        /** @var mixed $data */
        $data = \is_string($json) ? \json_decode($json, true) : null;
        /** @var mixed $version */
        $version = \is_array($data) ? $data['.'] ?? null : null;

        return \is_string($version) && $version !== '' ? $version : null;
    }
}
