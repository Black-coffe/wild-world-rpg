<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-066 контент-пасс «оцифровка механик» №3 — боевые достижения (фидбэк владельца).
 *
 * Criteria_type npc_kills (логика в AchievementService) — монотонный счётчик побед над NPC
 * (characters.npc_kills). Путь боя починен v0.51.370 (4-баг каскад), счётчик снова растёт.
 * Новая категория combat (⚔️ Бой) — в web catMeta + Telegram CATEGORY_LABELS.
 *
 * Ладдер: Первая кровь(1) / Охотник(10) / Истребитель(50) / Гроза пустоши(200) / Легенда охоты(500).
 * Idempotent (по achievement_key UNIQUE). WipeManifest: achievements = KEEP. Новых таблиц нет.
 */
class SeedAchievementCombat extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [key, title, description, category, icon, criteria_type, target, points, sort]
        $rows = [
            ['kill_1',   'Первая кровь',     'Одолей первого врага. Пустошь не прощает слабых.',            'combat', '🩸', 'npc_kills', 1,   5,   450],
            ['kill_10',  'Охотник',          'Победи 10 тварей. Рука твёрже, глаз вернее.',                 'combat', '🏹', 'npc_kills', 10,  15,  451],
            ['kill_50',  'Истребитель',      'Победи 50 тварей. Пустошь стала чуть тише.',                  'combat', '⚔️', 'npc_kills', 50,  40,  452],
            ['kill_200', 'Гроза пустоши',    'Победи 200 тварей. Твоё имя шепчут у костров.',               'combat', '☠️', 'npc_kills', 200, 70,  453],
            ['kill_500', 'Легенда охоты',    'Победи 500 тварей. Ты — то, чего боятся сами хищники.',       'combat', '🐺', 'npc_kills', 500, 120, 454],
        ];

        foreach ($rows as $r) {
            [$key, $title, $desc, $cat, $icon, $type, $target, $points, $sort] = $r;
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
                'criteria_type'   => $type,
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
        $this->db->table('achievements')->whereIn('achievement_key', ['kill_1', 'kill_10', 'kill_50', 'kill_200', 'kill_500'])->delete();
    }
}
