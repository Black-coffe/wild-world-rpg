<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * transport-07 (ADR-174, docs/specs/transport-system/) — пять строк `tasks`
 * для пяти рецептов машин из `Config\CraftRecipes` (story 06). Без строки в
 * `tasks` `GenericCraftActionStart::handle()` падает на «Задача '...' не
 * найдена в базе» — рецепт существует в конфиге, но крафт не стартует.
 *
 * `type='craft'` обязателен: `GenericCraftActionStart::countDistinctActiveSlots()`
 * считает занятые слоты крафта строго по `tasks.type='craft'` (v0.51.265) —
 * без него задача не займёт слот и не будет видна очереди.
 * `handler_key='generic_craft'` дублирует `Worker::$taskHandlerKeyMap` (тот же
 * контракт, что и у остальных generic-craft задач) — приоритетный path
 * резолва обработчика через `HandlerRegistry` (ADR-023 B3).
 *
 * Idempotent по `tasks.name`.
 */
class SeedTransportCraftTasks extends Migration
{
    /** @var array<int, array{name:string,name_rus:string,description:string,min_duration:int,max_duration:int,difficulty_level:int}> */
    private const TASKS = [
        [
            'name'             => 'craftLightCart',
            'name_rus'         => 'Крафт лёгкой повозки',
            'description'      => 'Сборка лёгкой повозки на ручной тяге — древесина и ткань.',
            'min_duration'     => 30,
            'max_duration'     => 60,
            'difficulty_level' => 3,
        ],
        [
            'name'             => 'craftMountainBike',
            'name_rus'         => 'Крафт горного велосипеда',
            'description'      => 'Сборка горного велосипеда — древесина и выделанная шкура.',
            'min_duration'     => 45,
            'max_duration'     => 90,
            'difficulty_level' => 4,
        ],
        [
            'name'             => 'craftSnowmobile',
            'name_rus'         => 'Крафт снегохода',
            'description'      => 'Сборка снегохода на нефтяном ходу — металл-фрагменты и нефть.',
            'min_duration'     => 90,
            'max_duration'     => 150,
            'difficulty_level' => 6,
        ],
        [
            'name'             => 'craftDraftCart',
            'name_rus'         => 'Крафт тягловой повозки',
            'description'      => 'Сборка тягловой повозки — древесина, кожа и древесные материалы.',
            'min_duration'     => 90,
            'max_duration'     => 150,
            'difficulty_level' => 6,
        ],
        [
            'name'             => 'craftAutonomousDrone',
            'name_rus'         => 'Крафт автономного дрона',
            'description'      => 'Сборка автономного дрона — солнечные камни, проводка и электронные компоненты.',
            'min_duration'     => 150,
            'max_duration'     => 240,
            'difficulty_level' => 8,
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::TASKS as $taskRow) {
            $existing = $this->db->table('tasks')->where('name', $taskRow['name'])->get()->getRowArray();
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
                'difficulty_level'           => $taskRow['difficulty_level'],
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TASKS as $taskRow) {
            $this->db->table('tasks')->where('name', $taskRow['name'])->delete();
        }
    }
}
