<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S24 (v0.51.204, ROADMAP-CRAFT §8 Фаза 5) — Island-Farm (Фермеры).
 *
 * 4-й strategic-объект, закрывает асимметрию: до S24 только 3 из 4 фракций
 * (Военные/Инженеры/Партизаны) имели discovery-source +500. Фермеры —
 * единственная без strategic-объекта (FarmersHarvest квест = Greenhouse-L3,
 * не discovery; см. ADR-028). Фермер-тематика: древняя плодородная ферма
 * в Полях (biome 6).
 *
 * **Born active** (не `inactive` как 3 объекта 2026-05-08 — урок S21:
 * inactive + отсутствие spawn-case = мёртвая цепочка). Spawn-case для
 * 'IslandFarm' добавлен в WorldObjectGeneratorHandler этой сессией;
 * spawn-cap читается из GameSettings (соседняя миграция).
 *
 * Gate: Hoe (Мотыга) — accessible farm-tool (Фермеры — ранне-friendly
 * фракция). Discovery → +500 Фермерам (EndgameScoring) + auto-complete
 * StrategicCaptureIslandFarm (соседняя миграция) через StrategicLootHandler.
 *
 * Loot (name_en keys — как у 3 существующих strategic; Овощи id=47 НЕ
 * используем — у него name_en=NULL). handler_key выставлен явно (B6 path).
 * Idempotent: insert только если name_en отсутствует.
 */
class S24AddIslandFarm extends Migration
{
    public function up(): void
    {
        $existing = $this->db->table('world_objects')
            ->where('name_en', 'IslandFarm')
            ->get()
            ->getRowArray();
        if (! empty($existing)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('world_objects')->insert([
            'name'            => 'Старая ферма',
            'name_en'         => 'IslandFarm',
            'description'     => 'Заброшенная доколлапсная ферма посреди высокой травы. Выцветшие амбары, пустой силос, всё ещё плодоносящие фруктовые деревья и поскрипывающая ветряная мельница. Спокойные плодородные руины — Фермеры дорого ценят такие места.',
            'biome_id'        => json_encode(['6']),
            'max_count'       => 8,
            'discovery_tools' => json_encode([['Hoe' => '1']]),
            'contents'        => json_encode([[
                'resources' => [
                    'Crops' => '90',
                    'Fruit' => '60',
                    'Wood'  => '70',
                    'Clay'  => '40',
                    'Water' => '60',
                ],
                'crafted_items' => [
                    'BasicMedKit' => '4',
                    'Bandage'     => '6',
                    'Hoe'         => '2',
                ],
            ]]),
            'respawn_time'    => 18,
            'status'          => 'active',
            'handler_key'     => 'world_object_strategic_loot',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('world_objects')
            ->where('name_en', 'IslandFarm')
            ->delete();
    }
}
