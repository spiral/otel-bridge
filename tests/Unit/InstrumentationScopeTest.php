<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit;

use Spiral\OpenTelemetry\InstrumentationScope;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(InstrumentationScope::class)]
final class InstrumentationScopeTest
{
    public function versionComesFromTheShippedManifest(): void
    {
        $manifest = \json_decode((string) \file_get_contents(__DIR__ . '/../../resources/version.json'), true);

        Assert::same(InstrumentationScope::version(), $manifest['.']);
    }

    #[DataSet(['{".": "2.0.0"}', '2.0.0'], 'version')]
    #[DataSet(['{".": ""}', null], 'empty version')]
    #[DataSet(['{".": 2}', null], 'not a string')]
    #[DataSet(['{"packages": "2.0.0"}', null], 'no root package')]
    #[DataSet(['["2.0.0"]', null], 'list')]
    #[DataSet(['{".": "2.0', null], 'broken JSON')]
    #[DataSet(['', null], 'empty file')]
    public function versionIsReadFromManifest(string $content, ?string $expected): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'otel-bridge-version');
        \file_put_contents($file, $content);

        try {
            $version = InstrumentationScope::readVersion($file);
        } finally {
            \unlink($file);
        }

        Assert::same($version, $expected);
    }

    public function missingManifestGivesNoVersion(): void
    {
        Assert::null(InstrumentationScope::readVersion(__DIR__ . '/no-such-version.json'));
    }
}
