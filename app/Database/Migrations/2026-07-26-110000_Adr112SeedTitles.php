<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-112 (E11) — стартовый набор титулов (16: 7 level + 9 achievement).
 *
 * source_type='level' → выдаётся при char.level ≥ source_ref (ранние, каждый прогрессирующий
 * получает; L1 «Выживший» = у всех активных сразу → discoverability). source_type='achievement'
 * → при наличии достижения source_ref (аспирационные редкие). titles = KEEP. Idempotent (title_key).
 */
class Adr112SeedTitles extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [
            // ── level-источники (ранние → средние) ──
            $this->t('surv_lvl1',  'Выживший',           'level', '1',  '🪪', 10, 'Ты пережил первый день на пустоши. Носи с честью.'),
            $this->t('surv_lvl5',  'Бродяга пустоши',     'level', '5',  '🥾', 20, 'Достигни 5 уровня — дороги пустоши тебя признали.'),
            $this->t('surv_lvl10', 'Старожил',            'level', '10', '🎖', 30, 'Достигни 10 уровня. Ты уже не случайный гость.'),
            $this->t('surv_lvl15', 'Бывалый',             'level', '15', '🛡', 40, 'Достигни 15 уровня — пустошь больше не пугает.'),
            $this->t('surv_lvl20', 'Ветеран пустоши',     'level', '20', '⚔️', 50, 'Достигни 20 уровня. Порог мастерства за спиной.'),
            $this->t('surv_lvl30', 'Закалённый севером',  'level', '30', '❄️', 60, 'Достигни 30 уровня. Холод севера тебе нипочём.'),
            $this->t('surv_lvl50', 'Легенда острова',     'level', '50', '🌟', 70, 'Достигни 50 уровня. О тебе говорят у костров.'),

            // ── achievement-источники (аспирационные, редкие) ──
            $this->t('ach_boss_all',   'Хозяин Севера',       'achievement', 'boss_all',         '👑', 100, 'Открой достижение «Хозяин Севера» — одолей всех уникальных боссов глубокого севера.'),
            $this->t('ach_lvl100',     'Сотый',               'achievement', 'reach_level_100',  '💯', 110, 'Открой достижение «Сотый» — достигни 100 уровня.'),
            $this->t('ach_gold10m',    'Король золота',       'achievement', 'gold_10m',         '🪙', 120, 'Открой достижение «Король золота» — накопи 10 000 000 золота.'),
            $this->t('ach_sold500m',   'Барон пустоши',       'achievement', 'sold_500m',        '💰', 130, 'Открой достижение «Барон пустоши» — продай товаров на 500 000 000.'),
            $this->t('ach_explorer5k',  'Легенда странствий',  'achievement', 'explorer_5000',    '🧭', 140, 'Открой достижение «Легенда странствий» — разведай 5000 клеток.'),
            $this->t('ach_crafter500', 'Промышленник',        'achievement', 'crafter_500',      '🛠', 150, 'Открой достижение «Промышленник» — скрафти 500 предметов.'),
            $this->t('ach_quest100',   'Летописец',           'achievement', 'quest_100',        '📜', 160, 'Открой достижение «Летописец» — заверши 100 квестов.'),
            $this->t('ach_kill500',    'Легенда охоты',       'achievement', 'kill_500',         '🎯', 170, 'Открой достижение «Легенда охоты» — одолей 500 тварей.'),
            $this->t('ach_survive365', 'Бессмертный',         'achievement', 'survive_365',      '🩸', 180, 'Открой достижение «Бессмертный» — выживи 365 дней без гибели.'),
        ];

        foreach ($rows as $r) {
            $exists = $this->db->table('titles')->where('title_key', $r['title_key'])->countAllResults();
            if ($exists === 0) {
                $r['created_at'] = $now;
                $r['updated_at'] = $now;
                $this->db->table('titles')->insert($r);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function t(string $key, string $name, string $sourceType, string $sourceRef, string $icon, int $sort, string $desc): array
    {
        return [
            'title_key'   => $key,
            'name'        => $name,
            'description' => $desc,
            'source_type' => $sourceType,
            'source_ref'  => $sourceRef,
            'icon'        => $icon,
            'sort_order'  => $sort,
            'enabled'     => 1,
        ];
    }

    public function down(): void
    {
        $this->db->table('titles')->where('title_key !=', '')->delete();
    }
}
