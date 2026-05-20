<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S25 (v0.51.205, ROADMAP-CRAFT §8 Фаза 5 — ЗАКРЫВАЕТ ФАЗУ) — 4 faction-unique
 * weapons (ADR-029). Завершает 1:1 цепочку эндгейм-войны:
 *   фракция → сценарий → strategic-объект → signature weapon.
 *
 *   - Военные (1)   BunkerRifle          (после StrategicCaptureBunker)
 *   - Инженеры (3)  TechnoBeamShotgun    (после StrategicCaptureTechnopark)
 *   - Партизаны (2) GhostCityKnife       (после StrategicCaptureGhostCity)
 *   - Фермеры (4)   FarmersHarvestScythe (после StrategicCaptureIslandFarm)
 *
 * Audit-first (2026-05-20): 4 weapon отсутствуют в `weapons` (genuine net-new,
 * в отличие от S17 «recipe для existing»). Доступ — фракция + квест (user pick:
 * true faction-exclusive, не quest-only): gate `required_faction` + `required_quest`
 * в CraftRecipes (GenericCraftActionStart enforcement). Crafted через
 * ProfessionalWorkbench (T3), output_type='weapon' → characters_weapons.
 *
 * Balance: Legendary niche-tier (dmg 32-40), между T3 generic GaussPistol(25)
 * и cap HydraPlasma(45). Каждая фракция получает ровно одно — faction-lock не
 * даёт собрать все 4 одному игроку. Idempotent.
 */
class S25AddFactionWeapons extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $weapons = [
            [
                'name'              => 'Бункерная штурмовая винтовка',
                'name_en'           => 'BunkerRifle',
                'description'       => 'Тяжёлая военная винтовка из арсенала заброшенного бункера. Сигнатурное оружие Военных — символ контроля над стратегическими точками.',
                'weapon_type'       => 'Ranged',
                'damage_value'      => 38,
                'damage_type'       => 'Physical',
                'range_value'       => 60,
                'attack_speed'      => 1.0,
                'special_effect'    => 'Бронебойные патроны: повышенный урон по тяжёлой броне.',
                'effect_type'       => 'armor_pierce',
                'limitation'        => 'Только фракция Военные + захват Бункера.',
                'limitation_type'   => 'faction_quest',
                'required_strength' => 6,
                'required_agility'  => 0,
                'required_intellect'=> 0,
                'required_level'    => 20,
                'rarity'            => 'Legendary',
                'durability'        => 120,
                'durability_max'    => 120,
                'weight'            => 5.0,
                'price'             => 45000,
                'ammo_type'         => 'rifle_rounds',
            ],
            [
                'name'              => 'Технолучевой дробовик',
                'name_en'           => 'TechnoBeamShotgun',
                'description'       => 'Доколлапсный лучевой коилган из развалин Технопарка. Сигнатурное оружие Инженеров — концентрированный энергетический залп.',
                'weapon_type'       => 'Energy',
                'damage_value'      => 40,
                'damage_type'       => 'Energy',
                'range_value'       => 35,
                'attack_speed'      => 0.8,
                'special_effect'    => 'Энергозалп: игнорирует часть физической брони.',
                'effect_type'       => 'energy_burst',
                'limitation'        => 'Только фракция Инженеры + захват Технопарка.',
                'limitation_type'   => 'faction_quest',
                'required_strength' => 4,
                'required_agility'  => 0,
                'required_intellect'=> 6,
                'required_level'    => 22,
                'rarity'            => 'Legendary',
                'durability'        => 110,
                'durability_max'    => 110,
                'weight'            => 4.5,
                'price'             => 48000,
                'ammo_type'         => 'energy_cell',
            ],
            [
                'name'              => 'Нож Города-призрака',
                'name_en'           => 'GhostCityKnife',
                'description'       => 'Балансированный нож контрабандистов из теней Города-призрака. Сигнатурное оружие Партизан — быстрый и бесшумный.',
                'weapon_type'       => 'Melee',
                'damage_value'      => 32,
                'damage_type'       => 'Physical',
                'range_value'       => 2,
                'attack_speed'      => 1.8,
                'special_effect'    => 'Скрытая атака: бонус инициативы и шанс крита.',
                'effect_type'       => 'stealth_strike',
                'limitation'        => 'Только фракция Партизаны + захват Города-призрака.',
                'limitation_type'   => 'faction_quest',
                'required_strength' => 3,
                'required_agility'  => 7,
                'required_intellect'=> 0,
                'required_level'    => 14,
                'rarity'            => 'Legendary',
                'durability'        => 90,
                'durability_max'    => 90,
                'weight'            => 1.2,
                'price'             => 40000,
                'ammo_type'         => '',
            ],
            [
                'name'              => 'Коса Жатвы',
                'name_en'           => 'FarmersHarvestScythe',
                'description'       => 'Перекованная боевая коса со Старой фермы. Сигнатурное оружие Фермеров — широкий размашистый удар по нескольким врагам.',
                'weapon_type'       => 'Melee',
                'damage_value'      => 36,
                'damage_type'       => 'Physical',
                'range_value'       => 3,
                'attack_speed'      => 1.1,
                'special_effect'    => 'Размах: урон по area, бонус против группы.',
                'effect_type'       => 'sweep',
                'limitation'        => 'Только фракция Фермеры + захват Старой фермы.',
                'limitation_type'   => 'faction_quest',
                'required_strength' => 6,
                'required_agility'  => 2,
                'required_intellect'=> 0,
                'required_level'    => 16,
                'rarity'            => 'Legendary',
                'durability'        => 100,
                'durability_max'    => 100,
                'weight'            => 4.0,
                'price'             => 42000,
                'ammo_type'         => '',
            ],
        ];

        foreach ($weapons as $w) {
            $existing = $this->db->table('weapons')->where('name_en', $w['name_en'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('weapons')->insert($w + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $this->db->table('weapons')
            ->whereIn('name_en', ['BunkerRifle', 'TechnoBeamShotgun', 'GhostCityKnife', 'FarmersHarvestScythe'])
            ->delete();
    }
}
