<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S24 (v0.51.204) — quest StrategicCaptureIslandFarm (Фермеры).
 *
 * Консистентно с 3 другими strategic-квестами (StrategicCaptureBunker/
 * Technopark/GhostCity, миграция 2026-05-08-200000). Auto-complete через
 * StrategicLootHandler::checkStrategicCaptureQuest() по coupling-правилу
 * title_en = 'StrategicCapture' . object.name_en = 'StrategicCaptureIslandFarm'.
 *
 * **НЕ трогаем FarmersHarvest** (квест id=8) — он уже работает (auto-complete
 * на Greenhouse L3+, UpgradeBuildingAction v0.51.118). Это отдельная Greenhouse-
 * семантика; Island-Farm — discovery-семантика. Фермеры получают паритет с
 * остальными фракциями: discovery-квест + reward, поверх Greenhouse-квеста.
 *
 * min_level 10 / reward 4000 — entry-accessible (как GhostCity для Партизан),
 * Фермеры — ранне-friendly фракция. Idempotent.
 */
class S24AddIslandFarmQuest extends Migration
{
    public function up(): void
    {
        $existing = $this->db->table('quests')
            ->where('title_en', 'StrategicCaptureIslandFarm')
            ->get()
            ->getRowArray();
        if (! empty($existing)) {
            return;
        }

        $this->db->table('quests')->insert([
            'title_ru'    => 'Возрождение Старой фермы',
            'title_en'    => 'StrategicCaptureIslandFarm',
            'description' => 'Найди заброшенную плодородную ферму в полях и возьми её под контроль. Фермеры закладывают основу для эвакуации в новый мир.',
            'status'      => 'active',
            'min_level'   => 10,
            'reward_type' => 'gold',
            'reward'      => 4000,
        ]);
    }

    public function down(): void
    {
        $this->db->table('quests')
            ->where('title_en', 'StrategicCaptureIslandFarm')
            ->delete();
    }
}
