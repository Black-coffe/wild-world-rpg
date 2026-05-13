<?php

declare(strict_types=1);

namespace Tests\Support\Handlers\Fixtures;

use App\Attributes\HandlerKey;

#[HandlerKey(
    key:         'beta',
    displayName: 'Бета-обработчик',
)]
final class BetaHandler implements SampleEffectInterface
{
}
