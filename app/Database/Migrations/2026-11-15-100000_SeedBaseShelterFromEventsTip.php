<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE — совет о том, что база укрывает от опасных событий.
 *
 * Повод: багрепорт в чате сообщества (2026-08-09) — игрок потерял половину ресурсов
 * от «Метеоритного удара», не сходя с базы. Причина: резолв состояния считал игрока
 * «в поле», если у него шла задача (добыча/изучение), — и база не защищала никого,
 * кроме тех, кто вовсе ничем не занят. Починено в EffectResultFactory::playerStateKey.
 * Совет закрывает вторую половину: игроки не знали, что база вообще укрывает.
 *
 * Инварианты (ADR-134): про понятия и навигацию, без хрупких чисел баланса (у каждого
 * события своя доля защиты — от полной до частичной, значения живут в конфиге событий);
 * markdown-safe (парные *); utf8mb4-эмодзи; категория «события» из 14 ENUM;
 * идемпотентность по `title_en`; media-off (ADR-020) — весь смысл в тексте.
 */
class SeedBaseShelterFromEventsTip extends Migration
{
    private const TITLE_EN = 'BaseShelterFromEvents';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return;
        }

        $content = '🏠 *Своя база — укрытие от непогоды пустоши.* Когда приходит вредное мировое '
            . 'событие, находящийся на своей базе получает меньше — а от части событий '
            . 'не получает вовсе.'
            . "\n\n"
            . 'Считается именно *стоянка на клетке своей базы*. Запущенная добыча или изучение '
            . 'укрытия не отменяют: пока ты дома, дом прикрывает.'
            . "\n\n"
            . 'Смотри *«📋 Дела»* → *«🌟 Мировые события»*: там видно, что идёт сейчас и '
            . 'сколько осталось. Началось опасное, а ты в поле — возвращайся на базу '
            . 'и пережди.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🏠 База укрывает от вредных событий',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'события',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', self::TITLE_EN)->delete();
    }
}
