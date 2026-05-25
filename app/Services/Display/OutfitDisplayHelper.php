<?php

declare(strict_types=1);

namespace App\Services\Display;

/**
 * V15 (ROADMAP-vNext Фаза 3 closer) — pure-хелпер показа брони.
 *
 * Две задачи «честного closer'а»:
 *
 *  1. Резолв картинки фракционной брони (V14/ADR-046). `GearArmorDetailAction`
 *     держит карту картинок только для standard/-брони; 4 фракционные брони
 *     лежат в professional/ → при осмотре owned-брони показывалась заглушка
 *     default_armor.jpg. Хелпер отдаёт правильный относительный путь.
 *
 *  2. Честный показ сопротивлений. Колонки physical/fire/poison_resistance,
 *     speed/stealth_modifier реально читаются ТОЛЬКО в PvP
 *     (PvpDamageCalculator/PvpEquipmentRepository); PvE (EquipmentService)
 *     учитывает лишь armor_value. До V15 эти числа игроку не показывались
 *     вовсе, а flavor-описания намекали на эффект → полу-честно. Хелпер
 *     рендерит реальные ненулевые числа + честную сноску, что они работают
 *     в дуэлях (broня-защита — везде). Media-off инвариант: всё в тексте.
 *
 * Чистый, без зависимостей и состояния — легко юнит-тестируется.
 */
final class OutfitDisplayHelper
{
    /**
     * Фракционная броня V14 → относительный путь картинки (professional/).
     * Ключ = outfits.name_en.
     */
    private const FACTION_ARMOR_IMAGES = [
        'BunkerPlateArmor'    => 'uploads/telegram/craft/professional/bunker_plate_armor.jpg',
        'TechnoShieldSuit'    => 'uploads/telegram/craft/professional/techno_shield_suit.jpg',
        'GhostCityCloak'      => 'uploads/telegram/craft/professional/ghost_city_cloak.jpg',
        'FarmsteadGuardArmor' => 'uploads/telegram/craft/professional/farmstead_guard_armor.jpg',
    ];

    /** Честная сноска: сопротивления работают в PvP-дуэлях, armor_value (защита) — везде. */
    public const PVP_NOTE = '_⚔️ Сопротивления действуют в PvP-дуэлях; защита брони работает везде._';

    /**
     * Относительный путь картинки фракционной брони или null, если это не она.
     */
    public static function factionArmorImageRel(string $nameEn): ?string
    {
        return self::FACTION_ARMOR_IMAGES[$nameEn] ?? null;
    }

    /**
     * Ненулевые сопротивления брони → форматированные строки.
     *
     * Показываем ТОЛЬКО три сопротивления (physical/fire/poison), которые реально
     * читаются боёвкой (`PvpDamageCalculator::computeArmorResistance`, `/100`).
     * Значения хранятся в шкале 0–100 (как `armor_value`: `40` = 40%), поэтому
     * выводим число как есть с «%» (без ×100). `speed/stealth_modifier` НЕ
     * показываем — они нигде в боёвке не читаются (был бы новый lying-description).
     *
     * @param array<array-key,mixed> $outfit Строка из таблицы outfits (CI4 model row).
     * @return list<string>
     */
    public static function resistanceLines(array $outfit): array
    {
        $defs = [
            'physical_resistance' => '🛡 Физ. сопротивление',
            'fire_resistance'     => '🔥 Огнестойкость',
            'poison_resistance'   => '☣️ Сопр. ядам',
        ];

        $lines = [];
        foreach ($defs as $key => $label) {
            $raw = $outfit[$key] ?? 0;
            if (! is_numeric($raw)) {
                continue;
            }
            $val = (float) $raw;
            if ($val === 0.0) {
                continue;
            }
            $pct  = (int) round($val);
            $sign = $pct > 0 ? '+' : '';
            $lines[] = "{$label}: {$sign}{$pct}%";
        }

        return $lines;
    }

    /**
     * Есть ли у брони ненулевые спец-свойства (→ показывать блок + PvP-сноску).
     *
     * @param array<array-key,mixed> $outfit
     */
    public static function hasSpecialProperties(array $outfit): bool
    {
        return self::resistanceLines($outfit) !== [];
    }
}
