<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S9 (ROADMAP-RETENTION-10, ADR-147) — совет «Пустошь живая»: учит, что мир реагирует с первых
 * шагов (далёкая буря, шорох с выбором) и что выносливость надо беречь (искать воду).
 *
 * Дополняет ONBOARDING/GUIDE: проактивный напоминатель (TipService «Совет дня» + /tips). media-off
 * + markdown-safe (парные `*`). tip_type='события' (валидный ENUM). Про понятия/навигацию, НЕ числа
 * баланса. Idempotent по title_en. game_tips = KEEP.
 */
class S9SeedAtmosphereTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'EarlySurvivalAtmosphere';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🌪 *Пустошь живая — и реагирует с первых шагов.* На ходу тебе может встретиться '
            . 'далёкий раскат бури на горизонте или внезапный *шорох* в кустах с выбором: '
            . 'подкрасться, затаиться или уйти. Это безопасно — урона не будет, но настроение задаёт. '
            . 'И помни про *выносливость*: когда она на исходе, ищи воду на карте или вернись на базу '
            . 'передохнуть, иначе далеко не уйдёшь.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🌪 Пустошь живая — буря, шорох и вода',
            'title_en'   => $titleEn,
            'tip_type'   => 'события',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'EarlySurvivalAtmosphere')->delete();
    }
}
