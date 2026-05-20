<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S20 (v0.51.202) — 3 T3 utility tool craft tasks (ADR-026 reusable, ROADMAP-CRAFT Фаза 4 5/5 — закрывает фазу).
 *
 * Audit-first finding (2026-05-20): ROADMAP S20 предлагал INSERT новых
 * CombinationTool/PortableForge/AdvancedFishingNet — drift: их нет в коде.
 * Реальный gap: существующие tool-предметы без craft option:
 *   - DiamondPickaxe (id67) — УЖЕ в ToolManager bonus-map (работает, owned 5 чарами),
 *     но БЕЗ рецепта (чистый recipe-for-existing, как S17/S18).
 *   - Sapper Shovel (id68), Golden Hoe (id69) — в БД, owned, но НЕ в ToolManager
 *     map → ничего не делают. Оживляем через GameSettings overlay (S19-style).
 *
 * User pick (2026-05-20): Option A (3 existing tools) + bonus через GameSettings overlay.
 *
 * Предметы УЖЕ в crafted_items (type='tool') — НЕ вставляем item rows, только
 * tasks (completion через GenericCraftCompletionHandler default crafted_item path).
 *
 * Длительности — live-tunable через GameSettings (parallel S20SeedT3UtilityGameSettings).
 *
 * Idempotent: skip если tasks.name уже существует.
 */
class S20AddT3UtilityTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $tasks = [
            [
                'name'         => 'craftDiamondPickaxe',
                'name_rus'     => 'Крафт: Алмазная кирка (T3)',
                'description'  => 'Сборка алмазной кирки — премиальный инструмент добычи. Длительность 4 часа (override через tier3.utility.diamond_pickaxe.craft_duration_hours).',
                'min_duration' => 240,
                'max_duration' => 240,
            ],
            [
                'name'         => 'craftSapperShovel',
                'name_rus'     => 'Крафт: Сапёрная лопата (T3)',
                'description'  => 'Сборка усиленной сапёрной лопаты. Длительность 2 часа (override через tier3.utility.sapper_shovel.craft_duration_hours).',
                'min_duration' => 120,
                'max_duration' => 120,
            ],
            [
                'name'         => 'craftGoldenHoe',
                'name_rus'     => 'Крафт: Золотая мотыга (T3)',
                'description'  => 'Сборка золотой мотыги — премиальный фермерский инструмент. Длительность 2 часа (override через tier3.utility.golden_hoe.craft_duration_hours).',
                'min_duration' => 120,
                'max_duration' => 120,
            ],
        ];

        foreach ($tasks as $taskRow) {
            $existing = $this->db->table('tasks')
                ->where('name', $taskRow['name'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('tasks')->insert([
                'name'                       => $taskRow['name'],
                'handler_key'                => 'generic_craft',
                'name_rus'                   => $taskRow['name_rus'],
                'description'                => $taskRow['description'],
                'min_duration'               => $taskRow['min_duration'],
                'max_duration'               => $taskRow['max_duration'],
                'type'                       => 'craft',
                'difficulty_level'           => 4,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('tasks')
            ->whereIn('name', [
                'craftDiamondPickaxe',
                'craftSapperShovel',
                'craftGoldenHoe',
            ])
            ->delete();
    }
}
