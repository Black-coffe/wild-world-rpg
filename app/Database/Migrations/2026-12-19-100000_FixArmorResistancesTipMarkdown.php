<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «🛡 Сопротивления брони (PvP)» (title_en='ArmorResistancesPvP', V30SeedContentPassTipsV6ToV19) нёс в тексте
 * имя колонки `armor_value`. В legacy-Markdown `_` открывает курсив, который в тексте не закрывается, и Telegram
 * отвечал на рассылку «can't parse entities … byte offset 542»: игрок совет не получал, а просмотр уже записан.
 * На проде — 12 таких отказов с 03.10 (DailyTipBroadcastHandler). Заодно уходит английское имя колонки из текста игроку.
 *
 * Idempotent: замена по точной фразе через builder (префикс таблиц соблюдается); повторный запуск и чистая база (где совета нет) — no-op.
 */
class FixArmorResistancesTipMarkdown extends Migration
{
    private const TITLE_EN = 'ArmorResistancesPvP';
    private const OLD      = 'учитывается только базовое armor_value';
    private const NEW      = 'учитывается только базовая защита брони';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        if (! $this->db->tableExists('game_tips')) {
            return;
        }
        $rows = $this->db->table('game_tips')->select('id, content')->where('title_en', self::TITLE_EN)->get()->getResultArray();
        foreach ($rows as $row) {
            $content = is_string($row['content'] ?? null) ? $row['content'] : '';
            if (str_contains($content, $from)) {
                $this->db->table('game_tips')->where('id', $row['id'])->update(['content' => str_replace($from, $to, $content)]);
            }
        }
    }
}
