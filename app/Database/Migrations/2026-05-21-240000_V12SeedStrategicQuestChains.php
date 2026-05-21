<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V12 (vNext, Фаза 3, ADR-037) — strategic quest-chains: Bunker + Technopark.
 *
 *  - Fix: 4 strategic capture-квеста получают objective_type=discover_object →
 *    помечены как auto (StrategicLootHandler авто-создаёт+завершает на discovery,
 *    больше не показываются с dead start-кнопкой). Разблокирует T4-крафты (req_quest).
 *  - 2 цепочки по 3 этапа (через V11 prerequisite + V12 objective):
 *    Bunker (Военные):   CaptureBunker(discover) → BunkerArmory(level 18) → BunkerDominance(craft BunkerRifle)
 *    Technopark (Инжен.): CaptureTechnopark(discover) → TechnoparkResearch(level 21) → TechnoparkBreakthrough(craft TechnoBeamShotgun)
 *
 * GhostCity/IslandFarm: только fix (discover_objective) — их цепочки в V13.
 * Idempotent.
 */
class V12SeedStrategicQuestChains extends Migration
{
    public function up(): void
    {
        // 1. Fix: пометить 4 capture-квеста как discover_object (auto).
        $captures = [
            'StrategicCaptureBunker'     => 'Bunker',
            'StrategicCaptureTechnopark' => 'Technopark',
            'StrategicCaptureGhostCity'  => 'GhostCity',
            'StrategicCaptureIslandFarm' => 'IslandFarm',
        ];
        foreach ($captures as $titleEn => $objectNameEn) {
            $this->db->table('quests')->where('title_en', $titleEn)->update([
                'objective_type'   => 'discover_object',
                'objective_target' => $objectNameEn,
                'objective_qty'    => 1,
            ]);
        }

        // 2. Цепочки Bunker + Technopark (4 новых этапа).
        $chainQuests = [
            [
                'title_ru'           => 'Зачистка арсенала',
                'title_en'           => 'BunkerArmory',
                'description'        => 'Бункер захвачен — теперь закрепись. Военные требуют закалённого бойца: достигни 18 уровня, прежде чем вскрывать оружейные отсеки.',
                'status'             => 'active',
                'min_level'          => 15,
                'reward_type'        => 'gold',
                'reward'             => 3000,
                'prerequisite_quest' => 'StrategicCaptureBunker',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 18,
            ],
            [
                'title_ru'           => 'Господство Военных',
                'title_en'           => 'BunkerDominance',
                'description'        => 'Финал военной цепочки: выкуй Бункерную винтовку — символ доминирования фракции. (Скрафти BunkerRifle на Профессиональном верстаке.)',
                'status'             => 'active',
                'min_level'          => 20,
                'reward_type'        => 'gold',
                'reward'             => 10000,
                'prerequisite_quest' => 'BunkerArmory',
                'objective_type'     => 'craft_item',
                'objective_target'   => 'BunkerRifle',
                'objective_qty'      => 1,
            ],
            [
                'title_ru'           => 'Восстановление лаборатории',
                'title_en'           => 'TechnoparkResearch',
                'description'        => 'Технопарк открыт — пора восстановить науку. Инженеры доверят прорыв только опытному: достигни 21 уровня.',
                'status'             => 'active',
                'min_level'          => 20,
                'reward_type'        => 'gold',
                'reward'             => 5000,
                'prerequisite_quest' => 'StrategicCaptureTechnopark',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 21,
            ],
            [
                'title_ru'           => 'Научный прорыв',
                'title_en'           => 'TechnoparkBreakthrough',
                'description'        => 'Финал инженерной цепочки: собери Технолучевой дробовик — венец до-коллапсной науки. (Скрафти TechnoBeamShotgun на Профессиональном верстаке.)',
                'status'             => 'active',
                'min_level'          => 22,
                'reward_type'        => 'gold',
                'reward'             => 12000,
                'prerequisite_quest' => 'TechnoparkResearch',
                'objective_type'     => 'craft_item',
                'objective_target'   => 'TechnoBeamShotgun',
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
            'BunkerArmory', 'BunkerDominance', 'TechnoparkResearch', 'TechnoparkBreakthrough',
        ])->delete();

        $this->db->table('quests')->whereIn('title_en', [
            'StrategicCaptureBunker', 'StrategicCaptureTechnopark', 'StrategicCaptureGhostCity', 'StrategicCaptureIslandFarm',
        ])->update([
            'objective_type'   => null,
            'objective_target' => null,
            'objective_qty'    => 1,
        ]);
    }
}
