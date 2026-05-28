<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W13 (vNext2, Фаза 3, ADR-067) — «Сердце острова»: ландмарк-капстон главы 1.
 *
 * 5-й стратегический объект (faction-agnostic, в отличие от 4 фракционных) — точка
 * входа в островную story-arc «закрытие главы 1». Reuse S24-паттерна (generic
 * generateStrategicObject + StrategicLootHandler + checkStrategicCaptureQuest):
 * discovery → авто-завершение `StrategicCaptureIslandHeart` + advanceChain → арка.
 *
 * **Dormant на проде:** spawn-cap `world.strategic.islandheart.max_spawns`=0 (соседняя
 * миграция) → генератор НЕ спавнит (effectiveMaxCount=0, IslandHeart ∈ STRATEGIC_SPAWN_TYPES).
 * max_count=0 — fallback-страховка, если GameSettings недоступен. status='active' (чтобы при
 * активации флип spawn-cap>0 заспавнил без правок схемы). Биом 8 (Вулканические — «сердце»
 * острова, драматично; биом используется Бункером → есть клетки на проде).
 *
 * discovery_tools пусто (no gate) — путь к капстону = исследование огромной карты + высоко-
 * уровневая арка, не предмет. Idempotent.
 */
class W13AddIslandHeartObject extends Migration
{
    public function up(): void
    {
        $existing = $this->db->table('world_objects')->where('name_en', 'IslandHeart')->get()->getRowArray();
        if (! empty($existing)) {
            return;
        }
        $now = date('Y-m-d H:i:s');

        $this->db->table('world_objects')->insert([
            'name'            => 'Сердце острова',
            'name_en'         => 'IslandHeart',
            'description'     => 'Глубокая расщелина в чёрной вулканической породе, откуда поднимается слабое тепло и ровный гул. В стенах — вмурованные доколлапсные конструкции, оплавленные кабели, погасшие приборы. Здесь бьётся то, что держало остров стабильным посреди мёртвого мира. Кажется, оно ещё живо.',
            'biome_id'        => json_encode(['8']),
            'max_count'       => 0,
            'discovery_tools' => json_encode([]),
            'contents'        => json_encode([[
                'resources' => [
                    'Stones'              => '120',
                    'metalFragments'      => '90',
                    'electronicComponents'=> '40',
                    'Amber'               => '30',
                ],
                'crafted_items' => [
                    'BasicMedKit' => '5',
                ],
            ]]),
            'respawn_time'    => 48,
            'status'          => 'active',
            'handler_key'     => 'world_object_strategic_loot',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('world_objects')->where('name_en', 'IslandHeart')->delete();
    }
}
