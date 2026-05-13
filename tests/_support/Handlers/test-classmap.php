<?php

declare(strict_types=1);

/**
 * Fixture-classmap для HandlerDiscoveryScanner unit-тестов (Phase B1, ADR-023).
 *
 * Scanner получает этот файл через `composerClassmapPath` constructor arg
 * и итерирует пары `class FQCN => path`. Реальная Composer class-map
 * не используется в тестах (избегаем зависимости от build-state и сторонних
 * классов в проекте).
 */

$baseDir = __DIR__ . '/Fixtures/';

return [
    'Tests\\Support\\Handlers\\Fixtures\\SampleEffectInterface' => $baseDir . 'SampleEffectInterface.php',
    'Tests\\Support\\Handlers\\Fixtures\\SampleTaskInterface'   => $baseDir . 'SampleTaskInterface.php',
    'Tests\\Support\\Handlers\\Fixtures\\AlphaHandler'          => $baseDir . 'AlphaHandler.php',
    'Tests\\Support\\Handlers\\Fixtures\\BetaHandler'           => $baseDir . 'BetaHandler.php',
    'Tests\\Support\\Handlers\\Fixtures\\GammaTaskHandler'      => $baseDir . 'GammaTaskHandler.php',
    'Tests\\Support\\Handlers\\Fixtures\\UntaggedHandler'       => $baseDir . 'UntaggedHandler.php',
    'Tests\\Support\\Handlers\\Fixtures\\AbstractParent'        => $baseDir . 'AbstractParent.php',
];
