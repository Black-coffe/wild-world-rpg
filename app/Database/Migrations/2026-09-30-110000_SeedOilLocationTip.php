<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет «где добывать нефть».
 *
 * След вопроса игрока Евгения 2026-07-02: «где нефть добывать?». Нефть — редкий (rarity 1)
 * добываемый ресурс биомов Вулканические территории и Пустыни (сверено с БД testbot:
 * resources.biome_id='8,9'), но узнать это изнутри игры было негде. Совет закрывает
 * findability-разрыв (дополняет раздел «⛏ Добыча» в /guide).
 *
 * Про навигацию/понятия (какой биом), БЕЗ хрупких чисел баланса (точный уровень-гейт не
 * называем — он тюнится; говорим «с ростом уровня»). markdown-safe (парные *),
 * utf8mb4-эмодзи. Idempotent (по title_en). tip_type='ресурсы' (валидное ENUM-значение).
 */
class SeedOilLocationTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'OilLocation';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🛢️ *Где искать нефть:* это редкое сырьё добывают обычной добычей в '
            . '*Вулканических территориях* и *Пустынях* — иди в эти биомы и жми добычу. Открывается '
            . 'нефть не сразу, а с ростом уровня (сырьё средней-поздней игры), так что на старте её не '
            . 'найти — это нормально. Сама нефть не крафтится: наоборот, это топливо и сырьё для '
            . 'продвинутых рецептов (электроника) и ресурс для высоких построек. Ещё немного нефти '
            . 'приносит обыск охраняемых руин на севере.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🛢️ Где добывать нефть',
            'title_en'   => $titleEn,
            'tip_type'   => 'ресурсы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'OilLocation')->delete();
    }
}
