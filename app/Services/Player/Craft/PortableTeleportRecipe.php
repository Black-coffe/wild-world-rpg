<?php

declare(strict_types=1);

namespace App\Services\Player\Craft;

use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * Единый источник истины рецепта «📡 Портативный телепорт» (`PortableTeleport`).
 *
 * Повод (2026-08-06): предмет существовал в `crafted_items` с 2025 года и полностью
 * работал на использование (`TeleportUseValidator::validatePortable` → `TeleportExecutor`
 * → `TeleportItemConsumer::consumePortable`), но **получить его было нечем** — рецепта
 * не существовало ни в одном экране крафта. На проде: 0 владельцев, 0 применений.
 * Классический BUILT-BUT-DEAD (memory `feedback_audit_first_for_roadmap_sessions`).
 *
 * Почему рецепт живёт ЗДЕСЬ, а не двумя копиями в action'ах (как у соседей —
 * `TeleportBeaconBasic2Action` / `StartCraftTeleportBeaconBasic2Action`): у соседей список
 * материалов продублирован в экране требований и в экране списания, и комментарий прямо
 * просит «значения должны совпадать» — то есть расхождение держится на внимательности.
 * Здесь список один: показываем и списываем одно и то же по построению.
 *
 * Числа баланса (золото / длительность / заряды / уровень / killswitch) — admin-tunable
 * через GameSettings `craft.portable_teleport.*` (ADR-024). Материалы остаются в коде,
 * как у всех рецептов проекта (в GameSettings живут длительности и множители, не составы).
 *
 * 🔴 Анти-эксплойт (урок ADR-157): цена продажи предмета торговцу считается от
 * `crafted_items.price` и достигает `price × 1.0` при максимальной карме. Поэтому
 * `GOLD_COST` обязан оставаться ВЫШЕ прайса предмета — иначе круг «скрафтил → продал»
 * печатает золото. Миграция `Adr166PortableTeleportCraftable` опускает прайс до 18 000
 * при стоимости крафта 30 000; при правке любого из двух чисел сверять инвариант
 * (гейтится тестом `PortableTeleportRecipeTest::testCraftCostExceedsVendorSellCeiling`).
 */
final class PortableTeleportRecipe
{
    use GameSettingsReaderTrait;

    public const ITEM_NAME_ENG = 'PortableTeleport';
    public const ITEM_NAME_RUS = 'Портативный телепорт';

    public const KEY_ENABLED   = 'craft.portable_teleport.enabled';
    public const KEY_GOLD      = 'craft.portable_teleport.gold_cost';
    public const KEY_DURATION  = 'craft.portable_teleport.duration_minutes';
    public const KEY_CHARGES   = 'craft.portable_teleport.charges';
    public const KEY_MIN_LEVEL = 'craft.portable_teleport.min_level';

    public const DEFAULT_ENABLED   = true;
    public const DEFAULT_GOLD      = 30000;
    public const DEFAULT_DURATION  = 60;
    public const DEFAULT_CHARGES   = 15;
    public const DEFAULT_MIN_LEVEL = 15;

    /** Потолок цены продажи торговцу = `crafted_items.price` (sell_multiplier ≤ 1.0). */
    public const VENDOR_BASE_PRICE = 18000;

    /** Сырьевые ресурсы: название в `resources.name` → количество. */
    public const RESOURCES = [
        'Кристаллы'       => 12,
        'Солнечные камни' => 18,
        'Редкие металлы'  => 10,
        'Минералы'        => 20,
    ];

    /** Крафт-компоненты: название в `crafted_items.name_rus` → количество. */
    public const COMPONENTS = [
        'Проводка'               => 30,
        'Электронные компоненты' => 12,
        'Ткань'                  => 10,
    ];

    /** Имя задачи в `tasks.name` (Worker роутит завершение по нему). */
    public const TASK_NAME = 'craftPortableTeleport';

    /** Killswitch: OFF → крафт закрыт (кнопка остаётся видимой и объясняет причину). */
    public function enabled(): bool
    {
        return $this->gsBool(self::KEY_ENABLED, self::DEFAULT_ENABLED);
    }

    public function goldCost(): int
    {
        return max(0, $this->gsInt(self::KEY_GOLD, self::DEFAULT_GOLD));
    }

    public function durationMinutes(): int
    {
        return max(1, $this->gsInt(self::KEY_DURATION, self::DEFAULT_DURATION));
    }

    /**
     * Зарядов у собранного устройства. Единственный источник для ОБОИХ путей:
     * выдача (`CraftCompletionPortableTeleportHandler`) и обновление стака при
     * расходе (`TeleportItemConsumer::consumePortable`) — иначе шаблон предмета и
     * настройка разъезжаются (memory `feedback_stack_merge_must_clamp_to_base`).
     */
    public function charges(): int
    {
        return max(1, $this->gsInt(self::KEY_CHARGES, self::DEFAULT_CHARGES));
    }

    public function minLevel(): int
    {
        return max(1, $this->gsInt(self::KEY_MIN_LEVEL, self::DEFAULT_MIN_LEVEL));
    }

    /** @return array<string,int> */
    public function resources(): array
    {
        return self::RESOURCES;
    }

    /** @return array<string,int> */
    public function components(): array
    {
        return self::COMPONENTS;
    }
}
