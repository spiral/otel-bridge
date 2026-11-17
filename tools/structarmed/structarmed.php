<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->withPresets(Preset::PSR4(), Preset::CODEQUALITY())
    ->layer('Clock', 'src/SystemClock.php')
    ->layer('Trace', 'src/Trace')
    ->layer('Propagation', 'src/Propagation')
    ->layer('Span', 'src/Span')
    ->layer('CurrentSpan', 'src/CurrentSpan.php')
    ->layer('Tracer', 'src/Tracer.php')
    ->layer('TracerFactory', 'src/TracerFactory.php')
    ->layer('Bootloader', 'src/Bootloader')
    ->ruleset([
        'Clock'         => [],
        'Trace'         => [],
        'Propagation'   => [],
        'Span'          => [],
        'CurrentSpan'   => ['Span'],
        'Tracer'        => ['Propagation', 'Span'],
        'TracerFactory' => ['+Tracer'],
        'Bootloader'    => ['Clock', 'Trace', '+TracerFactory'],
    ]);
