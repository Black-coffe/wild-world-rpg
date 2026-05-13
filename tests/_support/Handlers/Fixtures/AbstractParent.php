<?php

declare(strict_types=1);

namespace Tests\Support\Handlers\Fixtures;

use App\Attributes\HandlerKey;

/**
 * Абстрактный класс с атрибутом — должен быть пропущен scanner'ом
 * (handler регистрируется только если можно инстанцировать).
 */
#[HandlerKey(key: 'abstract', displayName: 'Не должен попасть в registry')]
abstract class AbstractParent
{
}
