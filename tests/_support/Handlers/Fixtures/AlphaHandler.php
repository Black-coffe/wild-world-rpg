<?php

declare(strict_types=1);

namespace Tests\Support\Handlers\Fixtures;

use App\Attributes\HandlerKey;

#[HandlerKey(
    key:         'alpha',
    displayName: 'Альфа-обработчик',
    description: 'Тестовый handler для unit-тестов (Phase B1).',
    inputSchema: [
        ['name' => 'threshold', 'type' => 'int', 'min' => 0, 'max' => 100],
    ],
)]
final class AlphaHandler implements SampleEffectInterface
{
}
