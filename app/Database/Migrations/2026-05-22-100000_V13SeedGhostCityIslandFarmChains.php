<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V13 (vNext, Фаза 3, ADR-037) — strategic quest-chains: GhostCity + IslandFarm.
 *
 * Достраивает 2 цепочки, отложенные в V12 (там GhostCity/IslandFarm получили
 * только fix — discover_object на capture-квесте). Движок уже на проде:
 *   - QuestObjectiveHandler (everyMinute) завершает char_level / craft_item;
 *   - QuestChainService::advanceChain авто-назначает следующий этап;
 *   - StrategicLootHandler авто-создаёт+завершает capture на discovery.
 * V13 = чистый data-seed (нового кода/механик нет), зеркало V12.
 *
 * Цепочки (через V11 prerequisite + V12 objective):
 *   GhostCity (Партизаны):  CaptureGhostCity(discover) → GhostCityResistance(level 12) → GhostCityDominance(craft GhostCityKnife)
 *   IslandFarm (Фермеры):   CaptureIslandFarm(discover) → IslandFarmCultivation(level 14) → IslandFarmAbundance(craft FarmersHarvestScythe)
 *
 * Финал каждой цепочки гейтит S25-крафт через required_quest=StrategicCapture<X>
 * (root цепочки): захват разблокирует крафт оружия, а сам крафт — финальная веха.
 * Idempotent.
 */
class V13SeedGhostCityIslandFarmChains extends Migration
{
    public function up(): void
    {
        $chainQuests = [
            // ── GhostCity (Партизаны, оружие GhostCityKnife L14) ──
            [
                'title_ru'           => 'Партизанская сеть',
                'title_en'           => 'GhostCityResistance',
                'description'        => 'Город-призрак под твоим контролем — сплети из выживших сеть сопротивления. Партизаны доверяют только бывалым: достигни 12 уровня, прежде чем поведёшь ячейки в бой.',
                'status'             => 'active',
                'min_level'          => 10,
                'reward_type'        => 'gold',
                'reward'             => 2000,
                'prerequisite_quest' => 'StrategicCaptureGhostCity',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 12,
            ],
            [
                'title_ru'           => 'Господство Партизан',
                'title_en'           => 'GhostCityDominance',
                'description'        => 'Финал партизанской цепочки: выкуй Нож Города-призрака — клинок, что разит из теней. (Скрафти GhostCityKnife на Профессиональном верстаке.)',
                'status'             => 'active',
                'min_level'          => 14,
                'reward_type'        => 'gold',
                'reward'             => 7000,
                'prerequisite_quest' => 'GhostCityResistance',
                'objective_type'     => 'craft_item',
                'objective_target'   => 'GhostCityKnife',
                'objective_qty'      => 1,
            ],
            // ── IslandFarm (Фермеры, оружие FarmersHarvestScythe L16) ──
            [
                'title_ru'           => 'Возрождение угодий',
                'title_en'           => 'IslandFarmCultivation',
                'description'        => 'Островная ферма твоя — подними заброшенные угодья из запустения. Фермеры ценят опыт работы с землёй: достигни 14 уровня, прежде чем встанешь во главе хозяйства.',
                'status'             => 'active',
                'min_level'          => 12,
                'reward_type'        => 'gold',
                'reward'             => 2500,
                'prerequisite_quest' => 'StrategicCaptureIslandFarm',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 14,
            ],
            [
                'title_ru'           => 'Изобилие Фермеров',
                'title_en'           => 'IslandFarmAbundance',
                'description'        => 'Финал фермерской цепочки: скуй Косу Жатвы — символ изобилия и нелёгкого труда. (Скрафти FarmersHarvestScythe на Профессиональном верстаке.)',
                'status'             => 'active',
                'min_level'          => 16,
                'reward_type'        => 'gold',
                'reward'             => 8000,
                'prerequisite_quest' => 'IslandFarmCultivation',
                'objective_type'     => 'craft_item',
                'objective_target'   => 'FarmersHarvestScythe',
                'objective_qty'      => 1,
            ],
        ];

        foreach ($chainQuests as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('quests')->insert($q);
        }
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'GhostCityResistance', 'GhostCityDominance', 'IslandFarmCultivation', 'IslandFarmAbundance',
        ])->delete();
    }
}
