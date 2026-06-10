<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-106 (хвост) — боссо-достижения: победы над уникальными хозяевами глубокого севера.
 *
 * Criteria boss_kill_* (логика в AchievementService) — EXISTS маркера
 * BOSS_KILL_<npc_name_en> в action_log (пишет PvEService::logBossKill при победе
 * игрока над is_boss-NPC); boss_kills_distinct — COUNT(DISTINCT) по всем боссам.
 * Категория combat (существует с боевого ладдера kill_*).
 *
 * Idempotent (по achievement_key UNIQUE). WipeManifest: achievements = KEEP,
 * action_log = PLAYER_DATA (уже классифицированы). Новых таблиц нет.
 */
class SeedAchievementNorthBosses extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [key, title, description, category, icon, criteria_type, target, points, sort]
        $rows = [
            ['boss_scar',      'Шрам на Шраме',    'Одолей Шрама, Мясника Пустоши — вожака, которого изгнали даже Песчаные Волки.', 'combat', '🔪', 'boss_kill_scar',      1, 90,  455],
            ['boss_cerberus',  'Цербер обесточен', 'Выведи из строя «Цербера» — довоенную машину, что до сих пор патрулирует север.', 'combat', '🤖', 'boss_kill_cerberus',  1, 100, 456],
            ['boss_patriarch', 'Конец легенды',    'Срази Патриарха Стаи — волка-мутанта, давшего имя Песчаным Волкам.',              'combat', '🐺', 'boss_kill_patriarch', 1, 120, 457],
            ['boss_all',       'Хозяин Севера',    'Победи всех троих хозяев глубокого севера: Шрама, «Цербера» и Патриарха Стаи.',   'combat', '👑', 'boss_kills_distinct', 3, 200, 458],
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
        $this->db->table('achievements')->whereIn('achievement_key', ['boss_scar', 'boss_cerberus', 'boss_patriarch', 'boss_all'])->delete();
    }
}
