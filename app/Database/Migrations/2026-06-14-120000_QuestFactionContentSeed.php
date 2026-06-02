<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 Фаза 3 — контент фракционных квестов (dormant).
 *
 * По 2 квеста на каждую из 4 фракций, переиспользуя существующие objective_type
 * (collect_resource / building_level / npc_kills) + quests.faction_id для гейтинга.
 * Невидимы/не прогрессят пока `quests.faction_quests_enabled`=OFF. Тематика под фракцию:
 *   • Милитари (1) — зачистки (npc_kills) + арсенал (building_level Arsenal)
 *   • Партизаны (2) — взрывчатка (collect Sulfur) + засады (npc_kills)
 *   • Инженеры (3) — электроника (collect PreCollapseElectronics) + робомастерская
 *   • Фермеры (4)  — урожай (collect Crops) + агрокомплекс (building_level Greenhouse)
 * Idempotent (по title_en).
 */
class QuestFactionContentSeed extends Migration
{
    public function up(): void
    {
        $quests = [
            // ─────────── Милитари (faction_id=1, военное развитие, PVP) ───────────
            $this->q('MilitarySweep', 'Зачистка территории', 1, 8, 'npc_kills', null, 30, 3500,
                'Милитари не просят землю — они её берут. Уничтожь 30 тварей и мародёров: периметр должен быть чистым.'),
            $this->q('MilitaryArsenal', 'Военный арсенал', 1, 15, 'building_level', 'Arsenal', 2, 6000,
                'Армия сильна снабжением. Прокачай Арсенал до 2 уровня — оружие и боеприпасы для всей фракции.'),

            // ─────────── Партизаны (faction_id=2, скрытность и саботаж, PVP) ───────────
            $this->q('PartisanExplosives', 'Взрывчатый запас', 2, 6, 'collect_resource', 'Sulfur', 40, 2000,
                'Партизан воюет не числом, а хитростью. Собери 40 серы — основа для зарядов, что рвут вражеские базы изнутри.'),
            $this->q('PartisanAmbush', 'Партизанская засада', 2, 12, 'npc_kills', null, 50, 4500,
                'Удар из тени — фирменный почерк. Сними 50 целей в вылазках: каждая засада ослабляет врага и питает легенду отряда.'),

            // ─────────── Инженеры (faction_id=3, технологии и роботы, PVE) ───────────
            $this->q('EngineerElectronics', 'Электронный архив', 3, 8, 'collect_resource', 'PreCollapseElectronics', 30, 3500,
                'До Коллапса электроника была повсюду — теперь это сокровище. Собери 30 единиц довоенной электроники для лабораторий фракции.'),
            $this->q('EngineerRobotics', 'Робомастерская', 3, 14, 'building_level', 'RoboticsWorkshop', 1, 6000,
                'Будущее за машинами. Построй Мастерскую робототехники — и Инженеры начнут собирать механических помощников.'),

            // ─────────── Фермеры (faction_id=4, агрокультура и пища, PVE) ───────────
            $this->q('FarmerGranary', 'Житница фракции', 4, 6, 'collect_resource', 'Crops', 150, 2500,
                'Фермеры кормят остров. Накопи 150 зерновых культур — стратегический запас, что переживёт любую зиму.'),
            $this->q('FarmerAgroComplex', 'Агрокомплекс', 4, 12, 'building_level', 'Greenhouse', 2, 5000,
                'Сытость — оружие Фермеров. Прокачай Теплицу до 2 уровня: стабильный урожай прокормит фракцию и наполнит рынок.'),
        ];

        foreach ($quests as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('quests')->insert($q);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function q(
        string $titleEn,
        string $titleRu,
        int $factionId,
        int $minLevel,
        string $objectiveType,
        ?string $objectiveTarget,
        int $objectiveQty,
        int $reward,
        string $description
    ): array {
        return [
            'title_ru'           => $titleRu,
            'title_en'           => $titleEn,
            'description'        => $description,
            'status'             => 'active',
            'min_level'          => $minLevel,
            'reward_type'        => 'gold',
            'reward'             => $reward,
            'prerequisite_quest' => null,
            'objective_type'     => $objectiveType,
            'objective_target'   => $objectiveTarget,
            'objective_qty'      => $objectiveQty,
            'branch_group'       => null,
            'branch_label'       => null,
            'faction_id'         => $factionId,
        ];
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'MilitarySweep', 'MilitaryArsenal',
            'PartisanExplosives', 'PartisanAmbush',
            'EngineerElectronics', 'EngineerRobotics',
            'FarmerGranary', 'FarmerAgroComplex',
        ])->delete();
    }
}
