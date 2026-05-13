<?php

declare(strict_types=1);

namespace Tests\Support\Handlers\Fixtures;

/**
 * Класс БЕЗ атрибута HandlerKey — должен быть пропущен scanner'ом.
 */
final class UntaggedHandler implements SampleEffectInterface
{
}
