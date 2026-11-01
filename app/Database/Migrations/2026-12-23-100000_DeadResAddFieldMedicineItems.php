<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D-DEAD-RES — четыре полевых лекарства первого верстака из мёртвого сырья.
 *
 * Срез прода 2026-11-01 (docs/specs/d-srez/report.md §2): семь самых накопленных
 * ресурсов без потребителя — Змеиный яд, Тропические насекомые, Редкие руды, Снег,
 * Капельники, Сера, Вулканический пепел. Эти предметы дают им потребителя через уже
 * работающий путь Аптечки и Провизии (`type='drug'`, лечение — GameSettings
 * `medical.<snake>.heal_*`, соседняя миграция). character_boost здесь — только дисплей.
 *
 * Цена — по правилу «крафт не печатает золото»: price × 1.10 ≤ Σ qty × sell_price
 * сырья (прод 2026-11-01); держит `CraftRecipesDeadResourcesTest`, который читает
 * {@see self::ITEMS}.
 *
 * Время крафта — в `tasks.min/max_duration` (минуты), как у всех рецептов первого
 * верстака: GameSettings-override `duration_override_setting_key` хранит целые часы
 * (hard_min 1 ч) и сделал бы полевое лекарство в 4–15 раз медленнее соседей.
 *
 * Не пишет в character_resources, склад и crafted_items_log.
 * Idempotent: skip, если crafted_items.name_eng / tasks.name уже есть.
 */
class DeadResAddFieldMedicineItems extends Migration
{
    /**
     * @var list<array{name_rus:string,name_eng:string,character_boost:string,price:int,required_level:int,description:string}>
     */
    public const ITEMS = [
        [
            'name_rus'        => 'Сыворотка от яда',
            'name_eng'        => 'Antivenom',
            'character_boost' => '[{"Здоровье": 25,"Выносливость": 0}]',
            'price'           => 70,
            'required_level'  => 5,
            'description'     => 'Сыворотка от яда — вытяжка змеиного яда, разведённая талым снегом. Полевое средство: быстро возвращает здоровье после укусов и ран.',
        ],
        [
            'name_rus'        => 'Хитиновый пластырь',
            'name_eng'        => 'ChitinPatch',
            'character_boost' => '[{"Здоровье": 18,"Выносливость": 5}]',
            'price'           => 30,
            'required_level'  => 2,
            'description'     => 'Хитиновый пластырь — толчёные панцири тропических насекомых на известковой основе из капельников. Затягивает ссадины и немного бодрит.',
        ],
        [
            'name_rus'        => 'Серная мазь',
            'name_eng'        => 'SulfurOintment',
            'character_boost' => '[{"Здоровье": 10,"Выносливость": 20}]',
            'price'           => 50,
            'required_level'  => 5,
            'description'     => 'Серная мазь — сера с вулканическим пеплом, растёртая в густую пасту. Разогревает мышцы и снимает усталость, слегка лечит.',
        ],
        [
            'name_rus'        => 'Минеральные таблетки',
            'name_eng'        => 'MineralTablets',
            'character_boost' => '[{"Здоровье": 5,"Выносливость": 30}]',
            'price'           => 60,
            'required_level'  => 7,
            'description'     => 'Минеральные таблетки — прессованный порошок редких руд и капельников на снеговой воде. Восполняют силы в долгом переходе.',
        ],
    ];

    /**
     * @var list<array{name:string,name_rus:string,description:string,min_duration:int,max_duration:int}>
     */
    public const TASKS = [
        [
            'name'         => 'craftAntivenom',
            'name_rus'     => 'Крафт: Сыворотка от яда',
            'description'  => 'Полевое лекарство первого верстака из змеиного яда и снега.',
            'min_duration' => 8,
            'max_duration' => 14,
        ],
        [
            'name'         => 'craftChitinPatch',
            'name_rus'     => 'Крафт: Хитиновый пластырь',
            'description'  => 'Полевое лекарство первого верстака из тропических насекомых и капельников.',
            'min_duration' => 4,
            'max_duration' => 7,
        ],
        [
            'name'         => 'craftSulfurOintment',
            'name_rus'     => 'Крафт: Серная мазь',
            'description'  => 'Полевое лекарство первого верстака из серы и вулканического пепла.',
            'min_duration' => 6,
            'max_duration' => 10,
        ],
        [
            'name'         => 'craftMineralTablets',
            'name_rus'     => 'Крафт: Минеральные таблетки',
            'description'  => 'Полевое лекарство первого верстака из редких руд, капельников и снега.',
            'min_duration' => 8,
            'max_duration' => 14,
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
