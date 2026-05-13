<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Attributes\HandlerKey;
use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — резерв для thematic-подій без механічного ефекту.
 *
 * Повертає applied=true з log_summary='thematic event' — гравець все ще
 * отримає start-нотіфікацію, але не буде ні damage ні buff.
 *
 * Корисно для майбутніх «декоративних» подій типу «Сонячне затемнення —
 * красиве небо, без механіки».
 */
#[HandlerKey(
    key: 'noop',
    displayName: 'Без эффекта',
    description: 'Тематическое событие без механического воздействия (резерв для декоративных событий).',
    inputSchema: [],
)]
final class NoOpEffect implements EventEffectInterface
{
    public function compute(array|\App\Entities\CharacterEntity $character, array $eventConfig, array $activeEvent, array $context): array
    {
        return EffectResultFactory::make([
            'applied'     => true,
            'log_summary' => 'thematic event',
            'magnitude'   => ['effect_kind' => 'noop'],
        ]);
    }
}
