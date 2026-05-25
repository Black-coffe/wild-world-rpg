<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V18 (ADR-049) — GameSettings для Robotics T2 (specialized robots) + миграция
 * скаляров баланса роботов из Config\GameBalance (ADR-024 drift fix).
 *
 * Две группы ключей (category=resources):
 *  1. T2-дифференциация: scout cells_multiplier, industrial yield_multiplier +
 *     extra_cells, killswitch t2.enabled (off → T2 ведёт себя как T1).
 *  2. Мигрированные скаляры (бывш. GameBalance, теперь admin-tunable): exploration
 *     cells_base / cells_per_level (последний оживает после фикса 999-бага в
 *     CompleteRobotExplorationHandler), gathering random_percent. Значения 1:1 с
 *     GameBalance-дефолтами → 0 изменения баланса; хендлеры читают через RobotService
 *     с fallback на эти дефолты.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускает существующие setting_key.
 */
class V18SeedRoboticsT2GameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            // ── 1. T2-дифференциация ─────────────────────────────────────────
            [
                'setting_key'        => 'robots.t2.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch слоя T2-роботов (Разведчик/Промышленник). true — T2 применяют свои множители (больше клеток / больше ресурсов). false — T2 ведут себя ровно как T1 (множители 1.0), без редеплоя.',
                'effect_text'        => 'RobotService::t2Enabled. false → explorationCellsMultiplierFor/gatheringYieldMultiplierFor/gatheringExtraCellsFor возвращают нейтральные значения (1.0 / 0).',
                'above_effect_text'  => 'true: T2-роботы сильнее T1 (оправдывают цену и гейт RoboticsWorkshop L2).',
                'below_effect_text'  => 'false: мгновенный откат T2-выгоды (скрафченные T2-роботы остаются, но работают как T1).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'robots.scout.cells_multiplier',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 1.6,
                'default_value_text' => '1.6',
                'rationale_text'     => 'Множитель числа клеток, открываемых Роботом-разведчиком (T2 explorer) за запуск. 1.6 = на 60% больше карты, чем у T1 Исследователя — оправдывает более дорогой крафт и гейт RoboticsWorkshop L2.',
                'effect_text'        => 'CompleteRobotExplorationHandler множит итоговые cellsToOpen на это значение, если запущен RobotScout (RobotService::explorationCellsMultiplierFor).',
                'above_effect_text'  => 'При 2.5 разведчик открывает в 2.5× больше клеток → T1 Исследователь теряет смысл, карта раскрывается слишком быстро.',
                'below_effect_text'  => 'При 1.05 разница с T1 (+5%) незаметна → T2-апгрейд не оправдан, BUILT-BUT-DEAD ощущение.',
                'recommended_min'    => '1.20',
                'recommended_max'    => '2.00',
                'hard_min'           => '1.00',
                'hard_max'           => '5.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'robots.industrial.yield_multiplier',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 1.5,
                'default_value_text' => '1.5',
                'rationale_text'     => 'Множитель количества добытых ресурсов Роботом-промышленником (T2 gatherer). 1.5 = +50% к выходу относительно T1 Добытчика — оправдывает цену и гейт L2.',
                'effect_text'        => 'CompleteRobotGatheringHandler множит итоговые количества ресурсов на это значение, если запущен RobotIndustrial (RobotService::gatheringYieldMultiplierFor).',
                'above_effect_text'  => 'При 3.0 добыча утраивается → ресурс-инфляция в эндгейме, обесценивает ручную добычу.',
                'below_effect_text'  => 'При 1.05 разница с T1 (+5%) незаметна → T2-апгрейд не оправдан.',
                'recommended_min'    => '1.20',
                'recommended_max'    => '2.00',
                'hard_min'           => '1.00',
                'hard_max'           => '5.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'robots.industrial.extra_cells',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Сколько ДОПОЛНИТЕЛЬНЫХ клеток вокруг базы обходит Робот-промышленник (T2) сверх обычного (= уровень мастерской). 1 = на одну клетку шире охват, чем у T1.',
                'effect_text'        => 'CompleteRobotGatheringHandler прибавляет это к desiredCellsCount, если запущен RobotIndustrial (RobotService::gatheringExtraCellsFor).',
                'above_effect_text'  => 'При 5 промышленник выгребает большой радиус за раз → быстро истощает окрестности, дисбаланс охвата.',
                'below_effect_text'  => 'При 0 преимущество промышленника только в yield_multiplier (охват = как у T1).',
                'recommended_min'    => '0',
                'recommended_max'    => '3',
                'hard_min'           => '0',
                'hard_max'           => '10',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],

            // ── 2. Мигрированные скаляры баланса (бывш. Config\GameBalance) ───
            [
                'setting_key'        => 'robots.exploration.cells_base',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Базовое число клеток, которое робот-исследователь открывает за час работы (при L1 мастерской). 50 — текущий дефолт GameBalance, переносим в admin-tunable (ADR-024).',
                'effect_text'        => 'CompleteRobotExplorationHandler: cellsPerHour = cells_base + (workshopLevel-1) × cells_per_level (RobotService::explorationCellsBase).',
                'above_effect_text'  => 'При 200 разведка вчетверо быстрее открывает карту → туман войны теряет ценность.',
                'below_effect_text'  => 'При 10 робот-исследователь почти бесполезен (часы ради нескольких клеток).',
                'recommended_min'    => '20',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'robots.exploration.cells_per_level',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => '+N клеток/час за каждый уровень RoboticsWorkshop свыше 1. 10 — дефолт GameBalance. ВАЖНО: до V18 этот knob был МЁРТВ (хендлер искал building_id=999 → уровень всегда 1); V18 чинит lookup, и значение оживает.',
                'effect_text'        => 'CompleteRobotExplorationHandler: cellsPerHour растёт на это значение за каждый уровень мастерской (RobotService::explorationCellsPerLevel).',
                'above_effect_text'  => 'При 50 высокоуровневая мастерская открывает огромные площади за запуск → стремительный разгон разведки.',
                'below_effect_text'  => 'При 0 уровень мастерской снова не влияет на скорость открытия (только на длительность сессии).',
                'recommended_min'    => '5',
                'recommended_max'    => '30',
                'hard_min'           => '0',
                'hard_max'           => '500',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'robots.gathering.random_percent',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => '±N% случайный разброс к итоговому количеству ресурсов, добытых роботом-добытчиком. 20 = ±20% (живой разброс, дефолт GameBalance), переносим в admin-tunable.',
                'effect_text'        => 'CompleteRobotGatheringHandler::calculateBiomeResources умножает количество на (1 ± rnd/100), где rnd ∈ [−N, N] (RobotService::gatheringRandomPercent).',
                'above_effect_text'  => 'При 80 добыча скачет ±80% → результат непредсказуем, фрустрация (повезло/не повезло).',
                'below_effect_text'  => 'При 0 добыча строго детерминирована (без разнообразия), зато предсказуема.',
                'recommended_min'    => '0',
                'recommended_max'    => '40',
                'hard_min'           => '0',
                'hard_max'           => '90',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue; // idempotent
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'robots.t2.enabled',
                'robots.scout.cells_multiplier',
                'robots.industrial.yield_multiplier',
                'robots.industrial.extra_cells',
                'robots.exploration.cells_base',
                'robots.exploration.cells_per_level',
                'robots.gathering.random_percent',
            ])
            ->delete();
    }
}
