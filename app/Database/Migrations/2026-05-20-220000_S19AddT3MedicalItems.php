<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S19 (v0.51.201) — 3 T3 medical items + craft tasks (ADR-026 reusable, ROADMAP-CRAFT Фаза 4 4/5).
 *
 * Audit-first finding (2026-05-20): ROADMAP S19 предлагал «SyntheticMedicine
 * (лечит любую болезнь) + EmergencyTransfusion + SurgicalKit, 3 dedicated
 * handler'а». Drift:
 *   1. «dedicated handlers» — stale. GenericCraftCompletionHandler default
 *      crafted_item path покрывает (пишет в crafted_items_log). Как S16-S18.
 *   2. «лечит любую болезнь» — НЕТ системы болезней в коде. Drug-эффекты
 *      трогают только health/tired (cap 100). «Эпидемия»/«Лихорадка» — это
 *      transient damage-события, лечить нечего. → переосмыслено как премиум-хилки.
 *   3. 3 предмета НЕ существуют в БД (на проде 10 drug items, ни один не эти).
 *      → genuine new content (в отличие от S17/S18 «recipe for existing»).
 *
 * Создаёт 3 crafted_items (type='drug') + 3 tasks (handler_key='generic_craft').
 *   - SyntheticMedicine    L20 — премиум full-restore (health 100 / tired 60), single-use
 *   - EmergencyTransfusion L18 — экстренное +80 HP (tired 20), single-use
 *   - SurgicalKit          L22 — multi-use (durability 3), health 50 / tired 30 per use
 *
 * Heal-значения — admin-tunable через GameSettings (parallel migration
 * S19SeedMedicalHealGameSettings, category=combat). character_boost тут — лишь
 * для отображения в PharmacyAction; фактический эффект читается из GameSettings.
 *
 * Длительности — live-tunable через GameSettings (S19SeedT3MedicalGameSettings).
 * Gating через recipe.required_crafted_items (ProfessionalWorkbench × 1) — pattern S17/S18.
 *
 * Idempotent: skip если crafted_items.name_eng / tasks.name уже существуют.
 */
class S19AddT3MedicalItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            [
                'name_rus'         => 'Синтетическое лекарство',
                'name_eng'         => 'SyntheticMedicine',
                'durability_count' => 1,
                'character_boost'  => '[{"Здоровье": 100,"Выносливость": 60}]',
                'price'            => 4000,
                'required_level'   => 20,
                'description'      => 'Синтетическое лекарство — продукт лабораторного синтеза из доколлапсного сырья. Полностью восстанавливает здоровье и снимает усталость. Премиальное средство «на крайний случай».',
            ],
            [
                'name_rus'         => 'Экстренное переливание',
                'name_eng'         => 'EmergencyTransfusion',
                'durability_count' => 1,
                'character_boost'  => '[{"Здоровье": 80,"Выносливость": 20}]',
                'price'            => 2500,
                'required_level'   => 18,
                'description'      => 'Экстренное переливание — полевой набор для переливания крови и плазмы. Резко поднимает здоровье. Одноразовый.',
            ],
            [
                'name_rus'         => 'Хирургический набор',
                'name_eng'         => 'SurgicalKit',
                'durability_count' => 3,
                'character_boost'  => '[{"Здоровье": 50,"Выносливость": 30}]',
                'price'            => 6000,
                'required_level'   => 22,
                'description'      => 'Хирургический набор — стерильные инструменты, шовный материал и зажимы в прочном кейсе. Многоразовый (3 применения): зашивает раны и восстанавливает силы.',
            ],
        ];

        foreach ($items as $item) {
            $existing = $this->db->table('crafted_items')
                ->where('name_eng', $item['name_eng'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('crafted_items')->insert([
                'name_rus'           => $item['name_rus'],
                'name_eng'           => $item['name_eng'],
                'type'               => 'drug',
                'direction_craft'    => 'treatment',
                'effect'             => 'boost',
                'durability_count'   => $item['durability_count'],
                'durability_time'    => null,
                'damage'             => 0,
                'armor'              => 0,
                'hp'                 => 0,
                'character_boost'    => $item['character_boost'],
                'weight'             => 0.20,
                'price'              => $item['price'],
                'stack_size'         => 5,
                'required_level'     => $item['required_level'],
                'required_skills'    => null,
                'required_resources' => null,
                'crafting_time'      => null,
                'crafting_location'  => 'Workbench Professional',
                'description'        => $item['description'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $tasks = [
            [
                'name'         => 'craftSyntheticMedicine',
                'name_rus'     => 'Крафт: Синтетическое лекарство (T3)',
                'description'  => 'Лабораторный синтез премиального лекарства. Длительность 3 часа (override через tier3.medical.synthetic_medicine.craft_duration_hours).',
                'min_duration' => 180,
                'max_duration' => 180,
            ],
            [
                'name'         => 'craftEmergencyTransfusion',
                'name_rus'     => 'Крафт: Экстренное переливание (T3)',
                'description'  => 'Сборка полевого набора для переливания. Длительность 2 часа (override через tier3.medical.emergency_transfusion.craft_duration_hours).',
                'min_duration' => 120,
                'max_duration' => 120,
            ],
            [
                'name'         => 'craftSurgicalKit',
                'name_rus'     => 'Крафт: Хирургический набор (T3)',
                'description'  => 'Сборка многоразового хирургического набора. Длительность 4 часа (override через tier3.medical.surgical_kit.craft_duration_hours).',
                'min_duration' => 240,
                'max_duration' => 240,
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
                'craftSyntheticMedicine',
                'craftEmergencyTransfusion',
                'craftSurgicalKit',
            ])
            ->delete();
        $this->db->table('crafted_items')
            ->whereIn('name_eng', [
                'SyntheticMedicine',
                'EmergencyTransfusion',
                'SurgicalKit',
            ])
            ->delete();
    }
}
