<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V14 (ROADMAP-vNext Фаза 3, ADR-046) — 4 faction-unique armor.
 * Сиблинг S25 weapons (ADR-029): каждая фракция получает signature-броню,
 * симметрично signature-оружию. Полный faction-лут: оружие + броня.
 *
 *   - Военные (1)   BunkerPlateArmor    (после StrategicCaptureBunker)
 *   - Инженеры (3)  TechnoShieldSuit    (после StrategicCaptureTechnopark)
 *   - Партизаны (2) GhostCityCloak      (после StrategicCaptureGhostCity)
 *   - Фермеры (4)   FarmsteadGuardArmor (после StrategicCaptureIslandFarm)
 *
 * Audit-first (2026-05-24): 4 outfit'а отсутствуют в `outfits` (genuine net-new).
 * Доступ — фракция + квест (gate `required_faction` + `required_quest` в CraftRecipes,
 * enforcement в GenericCraftActionStart — reuse ADR-029). Crafted через
 * ProfessionalWorkbench (T3), output_type='outfit' → characters_outfits (путь S18).
 *
 * Balance: Legendary niche (armor_value 22-32), в полосе существующих Legendary
 * (TitanPowerArmor 30 / TeslaShardArmor 27 / generic T3 22). Дифференцированы по
 * архетипу фракции (тяж. Военные ↔ лёгк. Партизаны). Idempotent.
 */
class V14AddFactionArmor extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $outfits = [
            [
                'name'                => 'Бункерная бронеплита',
                'name_en'             => 'BunkerPlateArmor',
                'description'         => 'Тяжёлая военная бронеплита из арсенала заброшенного бункера. Сигнатурная броня Военных — максимальная защита ценой подвижности.',
                'armor_type'          => 'Heavy',
                'slot'                => 'body',
                'armor_value'         => 32,
                // V15-фикс: шкала 0–100 (как armor_value; PvP делит на 100). Раньше
                // были фракции 0.20/0.05/… → ~0 эффект в PvP. См. V15-миграция.
                'physical_resistance' => 20,
                'fire_resistance'     => 5,
                'poison_resistance'   => 0,
                'speed_modifier'      => -8,
                'stealth_modifier'    => -5,
                'weight'              => 12.0,
                'durability'          => 140,
                'durability_max'      => 140,
                'required_strength'   => 8,
                'required_level'      => 20,
                'rarity'              => 'Legendary',
                'bonus_type'          => 'physical_resist',
                'special_bonus'       => 'Усиленные пластины: +20% к физическому сопротивлению.',
                'penalty_type'        => 'mobility',
                'penalty'             => 'Тяжёлая: −8% к скорости, −5% к скрытности.',
                'price'               => 45000,
            ],
            [
                'name'                => 'Техно-щитовой костюм',
                'name_en'             => 'TechnoShieldSuit',
                'description'         => 'Доколлапсный энергозащитный костюм из развалин Технопарка. Сигнатурная броня Инженеров — рассеивает термический и энергоурон.',
                'armor_type'          => 'Tech',
                'slot'                => 'body',
                'armor_value'         => 28,
                'physical_resistance' => 10,
                'fire_resistance'     => 25,
                'poison_resistance'   => 5,
                'speed_modifier'      => 0,
                'stealth_modifier'    => 0,
                'weight'              => 7.0,
                'durability'          => 120,
                'durability_max'      => 120,
                'required_strength'   => 5,
                'required_level'      => 22,
                'rarity'              => 'Legendary',
                'bonus_type'          => 'fire_resist',
                'special_bonus'       => 'Энергорассеивание: +25% к огнестойкости.',
                'penalty_type'        => '',
                'penalty'             => '',
                'price'               => 48000,
            ],
            [
                'name'                => 'Плащ Города-призрака',
                'name_en'             => 'GhostCityCloak',
                'description'         => 'Лёгкий маскировочный плащ контрабандистов из теней Города-призрака. Сигнатурная броня Партизан — скрытность и подвижность.',
                'armor_type'          => 'Light',
                'slot'                => 'body',
                'armor_value'         => 22,
                'physical_resistance' => 8,
                'fire_resistance'     => 0,
                'poison_resistance'   => 5,
                'speed_modifier'      => 10,
                'stealth_modifier'    => 20,
                'weight'              => 2.5,
                'durability'          => 90,
                'durability_max'      => 90,
                'required_strength'   => 2,
                'required_level'      => 14,
                'rarity'              => 'Legendary',
                'bonus_type'          => 'stealth',
                'special_bonus'       => 'Маскировка: +20% к скрытности, +10% к скорости.',
                'penalty_type'        => '',
                'penalty'             => '',
                'price'               => 40000,
            ],
            [
                'name'                => 'Броня хуторской стражи',
                'name_en'             => 'FarmsteadGuardArmor',
                'description'         => 'Прочная стёганая броня со Старой фермы, пропитанная защитным составом. Сигнатурная броня Фермеров — стойкость к ядам и износу.',
                'armor_type'          => 'Medium',
                'slot'                => 'body',
                'armor_value'         => 26,
                'physical_resistance' => 12,
                'fire_resistance'     => 5,
                'poison_resistance'   => 25,
                'speed_modifier'      => -2,
                'stealth_modifier'    => 0,
                'weight'              => 6.0,
                'durability'          => 130,
                'durability_max'      => 130,
                'required_strength'   => 5,
                'required_level'      => 16,
                'rarity'              => 'Legendary',
                'bonus_type'          => 'poison_resist',
                'special_bonus'       => 'Пропитка: +25% к сопротивлению ядам.',
                'penalty_type'        => '',
                'penalty'             => '',
                'price'               => 42000,
            ],
        ];

        foreach ($outfits as $o) {
            $existing = $this->db->table('outfits')->where('name_en', $o['name_en'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('outfits')->insert($o + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $this->db->table('outfits')
            ->whereIn('name_en', ['BunkerPlateArmor', 'TechnoShieldSuit', 'GhostCityCloak', 'FarmsteadGuardArmor'])
            ->delete();
    }
}
