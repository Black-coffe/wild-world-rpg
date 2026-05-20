<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S21 (v0.51.203, ROADMAP-CRAFT §8 Фаза 5) — оживление strategic-объектов.
 *
 * **Контекст (audit-first 2026-05-20).** Bunker/Technopark/GhostCity лежат в
 * world_objects с v0.51.109, handler (StrategicLootHandler) + discovery
 * (ObjectDiscoveryService) + endgame +500 + auto-complete квестов
 * (StrategicCapture*) — всё построено к v0.51.117, НО цепочка МЁРТВАЯ:
 *   1) world_objects.status='inactive' (админ не активировал);
 *   2) WorldObjectGeneratorHandler не имел spawn-case → даже активация ничего
 *      бы не дала (default no-op).
 * Прод-БД подтвердил: 0 спавнов strategic-объектов за всё время, 0 quest_steps.
 * Spawn-case добавлен в этой же сессии (handler). Эта миграция:
 *   - status inactive → active (генератор начинает их спавнить);
 *   - max_count 50/30/80 → rare baseline (реальный cap читается из GameSettings
 *     world.strategic.*.max_spawns; колонка = honest display + fallback);
 *   - discovery_tools: ослабление непроходимых multi-tool гейтов.
 *
 * **Discovery-tools было/стало.** Handler требует ВСЕ инструменты из
 * requiredTools[0]:
 *   - Bunker: {IronPickaxe, DiamondPickaxe} → {IronPickaxe}. L15-квест
 *     StrategicCaptureBunker недостижим с требованием DiamondPickaxe (L20-гейт).
 *   - Technopark: {DiamondPickaxe, IronPickaxe, Hoe} (3 инструмента!) →
 *     {DiamondPickaxe}. Связь с T3-инструментом S20 (П5 «tied to T3»).
 *   - GhostCity: {IronPickaxe} — без изменений (уже single, L12 доступно).
 *
 * Idempotent: UPDATE по name_en. Reversible (down → inactive + старые значения).
 */
class S21ActivateStrategicObjects extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            'Bunker' => [
                'status'          => 'active',
                'max_count'       => 5,
                'discovery_tools' => json_encode([['IronPickaxe' => '1']]),
                'updated_at'      => $now,
            ],
            'Technopark' => [
                'status'          => 'active',
                'max_count'       => 4,
                'discovery_tools' => json_encode([['DiamondPickaxe' => '1']]),
                'updated_at'      => $now,
            ],
            'GhostCity' => [
                'status'          => 'active',
                'max_count'       => 8,
                'discovery_tools' => json_encode([['IronPickaxe' => '1']]),
                'updated_at'      => $now,
            ],
        ];

        foreach ($rows as $nameEn => $update) {
            $this->db->table('world_objects')
                ->where('name_en', $nameEn)
                ->update($update);
        }
    }

    public function down(): void
    {
        $now = date('Y-m-d H:i:s');

        // Восстанавливаем дореволюционные значения (миграция 2026-05-08-180000).
        $rows = [
            'Bunker' => [
                'status'          => 'inactive',
                'max_count'       => 50,
                'discovery_tools' => json_encode([['IronPickaxe' => '1', 'DiamondPickaxe' => '1']]),
                'updated_at'      => $now,
            ],
            'Technopark' => [
                'status'          => 'inactive',
                'max_count'       => 30,
                'discovery_tools' => json_encode([['DiamondPickaxe' => '1', 'IronPickaxe' => '1', 'Hoe' => '1']]),
                'updated_at'      => $now,
            ],
            'GhostCity' => [
                'status'          => 'inactive',
                'max_count'       => 80,
                'discovery_tools' => json_encode([['IronPickaxe' => '1']]),
                'updated_at'      => $now,
            ],
        ];

        foreach ($rows as $nameEn => $update) {
            $this->db->table('world_objects')
                ->where('name_en', $nameEn)
                ->update($update);
        }
    }
}
