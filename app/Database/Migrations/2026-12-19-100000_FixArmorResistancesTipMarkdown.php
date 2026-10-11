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
 * Idempotent: REPLACE по точной фразе; повторный запуск и чистая база (где совета нет) — no-op.
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
        $this->db->query(
            'UPDATE game_tips SET content = REPLACE(content, ?, ?) WHERE title_en = ?',
            [$from, $to, self::TITLE_EN]
        );
    }
}
