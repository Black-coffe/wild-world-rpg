<?php

namespace App\Services\Events;

/**
 * v0.32.1 — людиномовні підписи для effect_kind enum.
 *
 * Використовується:
 *   - NotificationPolicy.buildStartKeyboard / buildEndKeyboard — для тексту кнопки
 *     «🚫 Без подій з уроном» (замість незрозумілого «Не такие события»)
 *   - EventPrefAction.computeUpdate — для toast-confirmation
 *
 * Single source of truth — не дублюємо mapping у двох місцях.
 *
 * Якщо у F7-серіях додасться новий effect_kind — додати рядок у $labels.
 */
final class KindLabels
{
    /**
     * effect_kind → людиномовна назва (родовий відмінок мн.: «без подій з ...»).
     */
    private const LABELS_RU = [
        'damage_health'       => 'з уроном',
        'damage_resources'    => 'з втратою ресурсів',
        'heal'                => 'з лікуванням',
        'attribute_boost'     => 'з буфами',
        'reveal_cells'        => 'з розвідкою мапи',
        'gold_grant'          => 'із золотом',
        'rare_resource_grant' => 'з рідкісними ресурсами',
        'task_extend'         => 'з затримкою задач',
        'gather_debuff'       => 'з debuff збору',
        'noop'                => 'фонові',
    ];

    /**
     * Повертає підпис «з ...» для use в шаблонах:
     *   «🚫 Без подій з уроном» / «Подій з уроном більше не буде».
     *
     * Невідомий kind → fallback на сам kind ('damage_health' як є).
     */
    public static function ru(string $effectKind): string
    {
        return self::LABELS_RU[$effectKind] ?? $effectKind;
    }

    /**
     * Чи відомий нам цей kind (для валідаційних тестів).
     */
    public static function isKnown(string $effectKind): bool
    {
        return isset(self::LABELS_RU[$effectKind]);
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::LABELS_RU;
    }
}
