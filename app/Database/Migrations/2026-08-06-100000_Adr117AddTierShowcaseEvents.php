<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E17 Ф2 (ADR-117) — 2 новых события среднего диапазона, витрина live tier-движка.
 *
 * Параллельно добавлены entries в Config\WorldEvents (effect_params) — связка по name_english.
 *   - RadioactiveFog (damage_health, биомы 2/3/8 север) — витрина HARM-tier (новичок ×0.5, ветеран ×1.3).
 *     Урон HP в открытом поле выжженного фонящего севера; защита — Bandage + нахождение на базе.
 *   - CleanSpring (heal, биомы 1/6 обжитые) — витрина BOON-tier (новичок ×1.3, ветеран ×1.0).
 *     Незаражённый родник восстанавливает HP/выносливость; новичку щедрее → удар по стене L1→L2 (E12).
 *
 * Реюз F7.2-движка (DamageHealthEffect / HealEffect — оба уже имеют tier-инъекцию E17 Ф1/Ф2).
 * Контент (как S10/MeteorImpact) — без killswitch; tier-факторы уже гейтятся своими killswitch'ами.
 * Idempotent по name_english. events=KEEP (контент-определения) → WipeManifest не тронут.
 */
class Adr117AddTierShowcaseEvents extends Migration
{
    /**
     * @var array<int, array{name:string,name_english:string,handler_key:string,description:string,biome_ids:string,duration:int,frequency:int,effect_type:string,effect_value:?int,img_path:string}>
     */
    private const EVENTS = [
        [
            'name'         => 'Фонящий туман',
            'name_english' => 'RadioactiveFog',
            'handler_key'  => 'damage_health',
            'description'  => 'С выжженных северных пустошей наползает плотный фонящий туман. Счётчик трещит, во рту привкус металла. Тех, кто застигнут в открытом поле, он травит исподволь — теряешь здоровье, пока не уйдёшь под крышу. На базе он не достаёт, а повязка-респиратор снижает дозу вдвое.',
            'biome_ids'    => '["2","3","8"]',
            'duration'     => 60,
            'frequency'    => 1,
            'effect_type'  => 'damage',
            'effect_value' => 7,
            'img_path'     => 'uploads/telegram/radioactive_fog.png',
        ],
        [
            'name'         => 'Чистый родник',
            'name_english' => 'CleanSpring',
            'handler_key'  => 'heal',
            'description'  => 'Из-под земли пробился редкий незаражённый родник — холодная, чистая, без привкуса ржавчины вода. Глоток такой воды смывает усталость и затягивает мелкие хвори. Кто оказался рядом — переводит дух и восстанавливает силы.',
            'biome_ids'    => '["1","6"]',
            'duration'     => 60,
            'frequency'    => 2,
            'effect_type'  => 'heal',
            'effect_value' => null,
            'img_path'     => 'uploads/telegram/clean_spring.png',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::EVENTS as $row) {
            $existing = $this->db->table('events')->where('name_english', $row['name_english'])->get()->getRowArray();
            if (! empty($existing)) {
                continue; // idempotent
            }

            $this->db->table('events')->insert([
                'name'                => $row['name'],
                'name_english'        => $row['name_english'],
                'handler_key'         => $row['handler_key'],
                'description'         => $row['description'],
                'biome_ids'           => $row['biome_ids'],
                'event_type'          => 'local',
                'random_coverage'     => 0,
                'duration'            => $row['duration'],
                'frequency_per_week'  => $row['frequency'],
                'effect_type'         => $row['effect_type'],
                'effect_value'        => $row['effect_value'],
                'protection_item_id'  => null,
                'start_time'          => null,
                'end_time'            => null,
                'img_path'            => $row['img_path'],
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::EVENTS as $row) {
            $this->db->table('events')->where('name_english', $row['name_english'])->delete();
        }
    }
}
