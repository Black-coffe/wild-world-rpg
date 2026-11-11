<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D-DEAD-RES волна 2 — пять полевых лекарств первого верстака из хвоста мёртвого сырья.
 *
 * Срез прода 2026-11-11 (docs/specs/d-dead-res-w2/brief.md): последние 14 ресурсов без
 * потребителя, каждый держат 11–18 из 53 активных игроков. Сырьё сгруппировано по биомам
 * добычи: льды (бальзам), вулканы (закваска), пещеры (жир), пустыня (соль), медсырьё (укол).
 * Путь — как у волны 1 (`DeadResAddFieldMedicineItems`): `type='drug'`, лечение —
 * GameSettings `medical.<snake>.heal_*` (соседняя миграция); character_boost — только дисплей.
 *
 * Цена — по правилу «крафт не печатает золото»: price × 1.10 ≤ Σ qty × sell_price сырья
 * (прод 2026-11-11); держит `CraftRecipesDeadResourcesWave2Test`, который читает {@see self::ITEMS}.
 * Время крафта — `tasks.min/max_duration` в минутах, как у всего первого верстака.
 *
 * Не пишет в character_resources, склад и crafted_items_log.
 * Idempotent: skip, если crafted_items.name_eng / tasks.name уже есть.
 */
class DeadResW2AddTailMedicineItems extends Migration
{
    /**
     * @var list<array{name_rus:string,name_eng:string,character_boost:string,price:int,required_level:int,description:string}>
     */
    public const ITEMS = [
        [
            'name_rus'        => 'Тюлений бальзам',
            'name_eng'        => 'SealBalm',
            'character_boost' => '[{"Здоровье": 12,"Выносливость": 25}]',
            'price'           => 110,
            'required_level'  => 6,
            'description'     => 'Тюлений бальзам — топлёное масло тюленя с настоем арктических цветов, остуженное на льду. Согревает и возвращает силы после стужи, немного лечит.',
        ],
        [
            'name_rus'        => 'Термальная закваска',
            'name_eng'        => 'ThermalCulture',
            'character_boost' => '[{"Здоровье": 8,"Выносливость": 28}]',
            'price'           => 75,
            'required_level'  => 5,
            'description'     => 'Термальная закваска — культура термофильных микроорганизмов из горячих источников, выдержанная в обсидиановой чаше. Снимает усталость в долгом походе.',
        ],
        [
            'name_rus'        => 'Пещерный жир',
            'name_eng'        => 'CaveTallow',
            'character_boost' => '[{"Здоровье": 22,"Выносливость": 8}]',
            'price'           => 65,
            'required_level'  => 4,
            'description'     => 'Пещерный жир — вытопленный жир подземных животных на известковой крошке сталактитов. Затягивает раны, слегка бодрит.',
        ],
        [
            'name_rus'        => 'Пустынная соль',
            'name_eng'        => 'DesertSalt',
            'character_boost' => '[{"Здоровье": 10,"Выносливость": 35}]',
            'price'           => 115,
            'required_level'  => 15,
            'description'     => 'Пустынная соль — толчёные миражные кристаллы, агат, фоссилии и драгоценная крошка. Щепоть под язык восполняет силы на жаре и в долгом переходе.',
        ],
        [
            'name_rus'        => 'Обезболивающий укол',
            'name_eng'        => 'PainkillerShot',
            'character_boost' => '[{"Здоровье": 40,"Выносливость": 15}]',
            'price'           => 245,
            'required_level'  => 18,
            'description'     => 'Обезболивающий укол — очищенное медицинское сырьё с разведённым ядом животных, простерилизованное на газовой горелке. Сильнейшее полевое средство первого верстака.',
        ],
    ];

    /**
     * @var list<array{name:string,name_rus:string,description:string,min_duration:int,max_duration:int}>
     */
    public const TASKS = [
        [
            'name'         => 'craftSealBalm',
            'name_rus'     => 'Крафт: Тюлений бальзам',
            'description'  => 'Полевое лекарство первого верстака из масла тюленя, арктических цветов и льда.',
            'min_duration' => 6,
            'max_duration' => 10,
        ],
        [
            'name'         => 'craftThermalCulture',
            'name_rus'     => 'Крафт: Термальная закваска',
            'description'  => 'Полевое лекарство первого верстака из термофильных микроорганизмов и обсидиана.',
            'min_duration' => 6,
            'max_duration' => 10,
        ],
        [
            'name'         => 'craftCaveTallow',
            'name_rus'     => 'Крафт: Пещерный жир',
            'description'  => 'Полевое лекарство первого верстака из подземных животных и сталактитов.',
            'min_duration' => 4,
            'max_duration' => 7,
        ],
        [
            'name'         => 'craftDesertSalt',
            'name_rus'     => 'Крафт: Пустынная соль',
            'description'  => 'Полевое лекарство первого верстака из миражных кристаллов, пустынного агата, фоссилий и драгоценных камней.',
            'min_duration' => 10,
            'max_duration' => 16,
        ],
        [
            'name'         => 'craftPainkillerShot',
            'name_rus'     => 'Крафт: Обезболивающий укол',
            'description'  => 'Полевое лекарство первого верстака из медицинского сырья, яда животных и природного газа.',
            'min_duration' => 10,
            'max_duration' => 16,
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::ITEMS as $item) {
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
                'durability_count'   => 1,
                'durability_time'    => null,
                'damage'             => 0,
                'armor'              => 0,
                'hp'                 => 0,
                'character_boost'    => $item['character_boost'],
                'weight'             => 0.10,
                'price'              => $item['price'],
                'stack_size'         => 3,
                'required_level'     => $item['required_level'],
                'required_skills'    => null,
                'required_resources' => null,
                'crafting_time'      => null,
                'crafting_location'  => 'all',
                'description'        => $item['description'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        foreach (self::TASKS as $taskRow) {
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
                'difficulty_level'           => 5,
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
        $this->db->table('tasks')
            ->whereIn('name', array_column(self::TASKS, 'name'))
            ->delete();
        $this->db->table('crafted_items')
            ->whereIn('name_eng', array_column(self::ITEMS, 'name_eng'))
            ->delete();
    }
}
