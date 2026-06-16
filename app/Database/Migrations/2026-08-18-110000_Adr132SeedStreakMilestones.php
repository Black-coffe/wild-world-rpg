<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-132 — контент Ф1: 8 вех серии (3/5/7/10/15/30/50/100) + 6 титулов-наград.
 *
 * Эскалация: низкие вехи (3/5/7) — припасы (золото + ресурс); средние/высокие (7+) добавляют
 * ПРЕСТИЖ (титул); 30/50/100 — чистый престиж (0 золота, только титул). Заморозка серии
 * (grants_freeze на 15/50) — forward-data для Ф3 (в Ф1 не выдаётся: нет characters.streak_freezes).
 *
 * Титулы source_type='streak' → НЕ авто-выдаются TitleCheckCron (qualifyingCharacterIds знает
 * только 'level'/'achievement' → []); выдаёт StreakMilestoneService при достижении вехи (как
 * 'collection'-титулы в ADR-119). Ресурсы — реальные имена из resources.name (Вода/Ягоды/Древесина).
 *
 * Идемпотентно: threshold_days/title_key UNIQUE; вставка только если ещё нет.
 * streak_milestones = KEEP, titles = KEEP.
 */
class Adr132SeedStreakMilestones extends Migration
{
    public function up(): void
    {
        // 1) Титулы-награды (source_type='streak', source_ref = порог в днях).
        $titles = [
            ['title_key' => 'streak_week',       'name' => 'Недельный',         'description' => 'Семь дней подряд на связи — пустошь запомнила это имя.',                 'source_type' => 'streak', 'source_ref' => '7',   'icon' => '🏅', 'sort_order' => 70, 'enabled' => 1],
            ['title_key' => 'streak_persistent', 'name' => 'Упорный',           'description' => 'Десять дней без пропусков. Упорство дороже припасов.',                   'source_type' => 'streak', 'source_ref' => '10',  'icon' => '🎖', 'sort_order' => 71, 'enabled' => 1],
            ['title_key' => 'streak_unbending',  'name' => 'Несгибаемый',       'description' => 'Пятнадцать дней серии. Таких не ломает ни буря, ни голод.',             'source_type' => 'streak', 'source_ref' => '15',  'icon' => '🎖', 'sort_order' => 72, 'enabled' => 1],
            ['title_key' => 'streak_month',      'name' => 'Ветеран месяца',    'description' => 'Месяц на связи. Радист отмечает таких отдельной зарубкой.',             'source_type' => 'streak', 'source_ref' => '30',  'icon' => '⭐', 'sort_order' => 73, 'enabled' => 1],
            ['title_key' => 'streak_fifty',      'name' => 'Несломленный',      'description' => 'Полста дней подряд. Пустошь так и не нашла, чем тебя сломить.',          'source_type' => 'streak', 'source_ref' => '50',  'icon' => '⭐', 'sort_order' => 74, 'enabled' => 1],
            ['title_key' => 'streak_century',    'name' => 'Столетник пустоши', 'description' => 'Сто дней без единого пропуска. Легенда, которую видно издалека.',       'source_type' => 'streak', 'source_ref' => '100', 'icon' => '👑', 'sort_order' => 75, 'enabled' => 1],
        ];
        foreach ($titles as $t) {
            if ($this->db->table('titles')->where('title_key', $t['title_key'])->countAllResults() === 0) {
                $this->db->table('titles')->insert($t);
            }
        }

        // 2) Вехи серии.
        $milestones = [
            ['threshold_days' => 3,   'name' => 'Втянулся',          'description' => 'Три дня подряд. Привычка выживать начинается с малого — вот тебе припасы на дорогу.',         'icon' => '🔥', 'reward_gold' => 100, 'reward_resource' => 'Вода',      'reward_resource_qty' => 5,  'title_key' => null,                'grants_freeze' => 0, 'sort_order' => 10, 'enabled' => 1],
            ['threshold_days' => 5,   'name' => 'Рука набита',       'description' => 'Пять дней. Ты уже знаешь, с какого конца браться за день — держи паёк.',                       'icon' => '🔥', 'reward_gold' => 150, 'reward_resource' => 'Ягоды',     'reward_resource_qty' => 5,  'title_key' => null,                'grants_freeze' => 0, 'sort_order' => 20, 'enabled' => 1],
            ['threshold_days' => 7,   'name' => 'Неделя в пустоши',  'description' => 'Целая неделя. Первый знак отличия — и доски на ремонт.',                                         'icon' => '🏅', 'reward_gold' => 200, 'reward_resource' => 'Древесина', 'reward_resource_qty' => 10, 'title_key' => 'streak_week',       'grants_freeze' => 0, 'sort_order' => 30, 'enabled' => 1],
            ['threshold_days' => 10,  'name' => 'Упорный',           'description' => 'Десять дней без пропусков. Упорство замечают — носи титул с гордостью.',                         'icon' => '🎖', 'reward_gold' => 300, 'reward_resource' => null,        'reward_resource_qty' => 0,  'title_key' => 'streak_persistent', 'grants_freeze' => 0, 'sort_order' => 40, 'enabled' => 1],
            ['threshold_days' => 15,  'name' => 'Несгибаемый',       'description' => 'Пятнадцать дней. Титул несгибаемого — и сигнальный костёр про запас на чёрный день.',           'icon' => '🎖', 'reward_gold' => 400, 'reward_resource' => null,        'reward_resource_qty' => 0,  'title_key' => 'streak_unbending',  'grants_freeze' => 1, 'sort_order' => 50, 'enabled' => 1],
            ['threshold_days' => 30,  'name' => 'Месяц на связи',    'description' => 'Месяц подряд. Это уже не привычка — это репутация. Титул ветерана месяца твой.',               'icon' => '⭐', 'reward_gold' => 0,   'reward_resource' => null,        'reward_resource_qty' => 0,  'title_key' => 'streak_month',      'grants_freeze' => 0, 'sort_order' => 60, 'enabled' => 1],
            ['threshold_days' => 50,  'name' => 'Полста',            'description' => 'Пятьдесят дней. Титул несломленного — и ещё один сигнальный костёр про запас.',                'icon' => '⭐', 'reward_gold' => 0,   'reward_resource' => null,        'reward_resource_qty' => 0,  'title_key' => 'streak_fifty',      'grants_freeze' => 1, 'sort_order' => 70, 'enabled' => 1],
            ['threshold_days' => 100, 'name' => 'Столетник пустоши', 'description' => 'Сто дней подряд. Легендарный титул, который видят все — на карточке и в публичном профиле.', 'icon' => '👑', 'reward_gold' => 0,   'reward_resource' => null,        'reward_resource_qty' => 0,  'title_key' => 'streak_century',    'grants_freeze' => 0, 'sort_order' => 80, 'enabled' => 1],
        ];
        foreach ($milestones as $m) {
            if ($this->db->table('streak_milestones')->where('threshold_days', $m['threshold_days'])->countAllResults() === 0) {
                $this->db->table('streak_milestones')->insert($m);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('streak_milestones')
            ->whereIn('threshold_days', [3, 5, 7, 10, 15, 30, 50, 100])->delete();
        $this->db->table('titles')->whereIn('title_key', [
            'streak_week', 'streak_persistent', 'streak_unbending', 'streak_month', 'streak_fifty', 'streak_century',
        ])->delete();
    }
}
