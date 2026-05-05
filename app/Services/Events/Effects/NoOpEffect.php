<?php

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — резерв для thematic-событий без механічного ефекту.
 *
 * Возвращает applied=true с log_summary='thematic event' — игрок все ще
 * отримаесть start-уведомлению, але не будет ни damage ни buff.
 *
 * Корисно для майбутніх «декоративних» событий типв «Сонячне затемнення —
 * красиве небо, без механіки».
 */
final class NoOpEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
    {
        return EffectResultFactory::make([
            'applied'     => true,
            'log_summary' => 'thematic event',
            'magnitude'   => ['effect_kind' => 'noop'],
        ]);
    }
}
