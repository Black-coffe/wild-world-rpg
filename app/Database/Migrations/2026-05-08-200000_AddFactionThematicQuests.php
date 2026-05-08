<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.117 — Quest expansion Step 2: 4 new faction-themed quests.
 *
 * 1:1 mapping faction → strategic object/activity (повторює endgame pattern):
 *   - StrategicCaptureBunker      (Militari) — discover Bunker
 *   - StrategicCaptureTechnopark  (Engineers) — discover Technopark
 *   - StrategicCaptureGhostCity   (Partisans) — discover GhostCity
 *   - FarmersHarvest              (Farmers) — Greenhouse upgrade lvl 3+
 *
 * Status='active' за default — players можуть ці started immediately.
 * Auto-detection через StrategicLootHandler (Step 2 v0.51.117) — коли
 * character discovers strategic object і має active matching quest →
 * mark is_completed + reward.
 *
 * Greenhouse quest detection deferred (player manual or admin verify).
 */
class AddFactionThematicQuests extends Migration
{
    public function up(): void
    {
        $this->db->table('quests')->insertBatch([
            [
                'title_ru'    => 'Захват Бункера',
                'title_en'    => 'StrategicCaptureBunker',
                'description' => 'Найди и захвати заброшенный военный бункер. Военная фракция стремится к доминированию.',
                'status'      => 'active',
                'min_level'   => 15,
                'reward_type' => 'gold',
                'reward'      => 5000,
            ],
            [
                'title_ru'    => 'Захват Технопарка',
                'title_en'    => 'StrategicCaptureTechnopark',
                'description' => 'Открой развалины высокотехнологичной лаборатории. Инженеры на пути к научному прорыву.',
                'status'      => 'active',
                'min_level'   => 20,
                'reward_type' => 'gold',
                'reward'      => 8000,
            ],
            [
                'title_ru'    => 'Тень Города-призрака',
                'title_en'    => 'StrategicCaptureGhostCity',
                'description' => 'Исследуй заброшенный город-призрак. Партизаны действуют в тени, готовясь к решающему удару.',
                'status'      => 'active',
                'min_level'   => 12,
                'reward_type' => 'gold',
                'reward'      => 4000,
            ],
            [
                'title_ru'    => 'Большой урожай',
                'title_en'    => 'FarmersHarvest',
                'description' => 'Развивай теплицу до 3-го уровня. Фермеры закладывают основу для эвакуации в новый мир.',
                'status'      => 'active',
                'min_level'   => 8,
                'reward_type' => 'gold',
                'reward'      => 3000,
            ],
        ]);
    }

    public function down(): void
    {
        $this->db->table('quests')
            ->whereIn('title_en', [
                'StrategicCaptureBunker',
                'StrategicCaptureTechnopark',
                'StrategicCaptureGhostCity',
                'FarmersHarvest',
            ])
            ->delete();
    }
}
