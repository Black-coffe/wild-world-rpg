<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W9 (ADR-066) — таблица достижений + seed 7 starter (по одному на criteria_type).
 *
 * Контент-таблица: медали добавляются строкой без релиза (как whats_new_topics/game_tips).
 * W10 = полный T1 пасс (20+ medals) + UI.
 *
 * criteria_type ∈ {char_level, explored_cells, craft_total, has_base, has_faction,
 * quests_completed, gold_total} — все читаются из persistent state (AchievementService
 * set-based SQL). criteria_target — порог (для has_base/has_faction = 1).
 *
 * 🔴 utf8mb4 (title/description/icon содержат emoji; memory feedback_emoji_content_needs_utf8mb4).
 * Idempotent: createTable ifNotExists + INSERT только если нет строки с тем же achievement_key.
 */
class W9CreateAchievementsTable extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('achievements')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'achievement_key' => ['type' => 'VARCHAR', 'constraint' => '64'],
                'title'           => ['type' => 'VARCHAR', 'constraint' => '255'],
                'description'     => ['type' => 'VARCHAR', 'constraint' => '500'],
                'category'        => ['type' => 'VARCHAR', 'constraint' => '32', 'default' => 'general'],
                'icon'            => ['type' => 'VARCHAR', 'constraint' => '16', 'default' => '🏅'],
                'criteria_type'   => ['type' => 'VARCHAR', 'constraint' => '32'],
                'criteria_target' => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
                'points'          => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'sort_order'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'enabled'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('achievement_key');
            $this->forge->addKey('criteria_type');
            $this->forge->createTable('achievements', false, [
                'ENGINE'  => 'InnoDB',
                'CHARSET' => 'utf8mb4',
                'COLLATE' => 'utf8mb4_unicode_ci',
            ]);
        }

        $now = date('Y-m-d H:i:s');

        // [key, title, description, category, icon, criteria_type, target, points, sort]
        $seed = [
            ['reach_level_5', 'Первые шаги', 'Достигни 5 уровня — добро пожаловать в большой мир.', 'progression', '🌱', 'char_level', 5, 10, 10],
            ['explorer_50', 'Разведчик', 'Разведай 50 клеток карты.', 'exploration', '🧭', 'explored_cells', 50, 15, 20],
            ['first_craft', 'Мастеровой', 'Сделай свой первый крафт.', 'crafting', '🔨', 'craft_total', 1, 5, 30],
            ['homesteader', 'Новосёл', 'Создай свою базу на клетке.', 'progression', '🏠', 'has_base', 1, 10, 40],
            ['path_chosen', 'Выбор пути', 'Вступи в одну из фракций.', 'social', '🏳️', 'has_faction', 1, 10, 50],
            ['quest_novice', 'Искатель', 'Заверши 3 квеста.', 'progression', '📜', 'quests_completed', 3, 15, 60],
            ['gold_hoarder', 'Кубышка', 'Накопи 10 000 золота.', 'economy', '💰', 'gold_total', 10000, 20, 70],
        ];

        foreach ($seed as [$key, $title, $desc, $cat, $icon, $ctype, $target, $points, $sort]) {
            $exists = $this->db->table('achievements')->where('achievement_key', $key)->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('achievements')->insert([
                'achievement_key' => $key,
                'title'           => $title,
                'description'     => $desc,
                'category'        => $cat,
                'icon'            => $icon,
                'criteria_type'   => $ctype,
                'criteria_target' => $target,
                'points'          => $points,
                'sort_order'      => $sort,
                'enabled'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('achievements', true);
    }
}
