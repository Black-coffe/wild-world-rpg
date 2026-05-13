<?php

declare(strict_types=1);

namespace Tests\Support\Handlers\Fixtures;

use App\Attributes\HandlerKey;

#[HandlerKey(
    key:         'gamma',
    displayName: 'Гамма-таск',
)]
final class GammaTaskHandler implements SampleTaskInterface
{
}
