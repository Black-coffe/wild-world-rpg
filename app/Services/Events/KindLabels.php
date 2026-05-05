<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * v0.32.1 — людиномовни підписи для effect_kind enum.
 *
 * Используетться:
 *   - NotificationPolicy.buildStartKeyboard / buildEndKeyboard — для текств кнопки
 *     «🚫 Без событий с уроном» (вместо незрозумілого «Не такие события»)
 *   - EventPrefAction.computeUpdate — для toast-confirmation
 *
 * Single source of truth — не дублюємо mapping в двох місцях.
 *
 * Если в F7-серіях додасться новый effect_kind — додати рядок в $labels.
 */
final class KindLabels
{
    /**
     * effect_kind → человеко-читаемое названание (предложный падеж мн.:
     * «без событий с ...»).
     */
    private const LABELS_RU = [
        'damage_health'       => 'с уроном',
        'damage_resources'    => 'с потерей ресурсов',
        'heal'                => 'с лечением',
        'attribute_boost'     => 'с бафами',
        'reveal_cells'        => 'с разведкой карты',
        'gold_grant'          => 'с золотом',
        'rare_resource_grant' => 'с редкими ресурсами',
        'task_extend'         => 'с задержкой задач',
        'gather_debuff'       => 'с debuff сбора',
        'noop'                => 'фоновые',
    ];

    /**
     * Возвращает подпись «с ...» для использования в шаблонах:
     *   «🚫 Без событий с уроном» / «Событий с уроном больше не будет».
     *
     * Неизвестный kind → fallback на сам kind ('damage_health' как есть).
     */
    public static function ru(string $effectKind): string
    {
        return self::LABELS_RU[$effectKind] ?? $effectKind;
    }

    /**
     * Если відомий нам цей kind (для валідаційних тестів).
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
