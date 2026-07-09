<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет про возвращённые входы (ADR-150 Слайс 5).
 *
 * Триггер: аудит 2026-07-09. Ремонт инструментов лежал в неймспейсе `Craft\Repair`, но кнопки
 * на экране Крафта не было вовсе (входы — Инвентарь и хаб поселения): 96 тапов у 6 игроков.
 * Склад базы — тема Базы, но с экрана Базы недостижим: 14 тапов у 9 игроков.
 *
 * Совет про навигацию/понятия, БЕЗ хрупких чисел баланса. Правдив: ремонт за золото реально
 * включён на проде (`npc.repair.enabled=1`), склад по-прежнему наполняется только карго-дроном
 * (не обещаем автодоставку). markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 * tip_type='крафт' (валидное ENUM).
 */
class SeedCraftBaseNavTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CraftBaseNav';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🪛 *Инструмент затупился?* Чинить его больше не нужно искать по инвентарю: '
            . 'на экране *«Крафт»* появилась кнопка *«🪛 Ремонт инструментов»* — почини своими '
            . 'ресурсами или сразу за золото у мастера. А на экране *«База»* теперь есть '
            . '*«📦 Склад базы»*: заглянуть в него можно прямо оттуда, не обходя через инвентарь. '
            . 'Помни только, что добыча сама на склад не едет — её привозит карго-дрон.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🪛 Ремонт инструментов и склад — на своих местах',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CraftBaseNav')->delete();
    }
}
