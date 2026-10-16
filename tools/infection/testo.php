<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

/**
 * The project config without the Acceptance suite, for Infection.
 *
 * Every acceptance test boots the app and exports to Trap, so it covers the bootloader and the
 * exporter of nearly every mutant: Infection then skips such a mutant as needing more time than the
 * timeout, or runs the whole suite for it. Unit and Feature cover the same code in about a second.
 */

/** @var ApplicationConfig $config */
$config = require \dirname(__DIR__, 2) . '/testo.php';

return new ApplicationConfig(
    src: $config->src,
    suites: \array_values(\array_filter(
        $config->suites,
        static fn(SuiteConfig $suite): bool => $suite->name !== 'Acceptance',
    )),
    plugins: $config->plugins,
);
