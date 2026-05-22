<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tips-overhaul Фаза A1 (ADR-038) — схема-prep советов: charset utf8mb4 + категории.
 *
 *  1. CONVERT game_tips → utf8mb4 (было utf8mb3, 3-байта → НЕ хранит emoji 4-байта,
 *     emoji превращались в '?'; поймано Tier-3 смоуком /tips). Нужно ДО записи emoji-контента.
 *  2. Расширяет ENUM `tip_type` новыми группами под механики V1-V13:
 *     земледелие, еда, квесты, фракции, бой, эндгейм, настройки
 *     (к существующим: биомы, ресурсы, крафт, персонаж, события, NPC, общие).
 *
 * Расширение enum новыми значениями не затрагивает существующие строки.
 */
class TipsAExtendCategories extends Migration
{
    private const ENUM_NEW = "ENUM('биомы','ресурсы','крафт','персонаж','события','NPC','общие','земледелие','еда','квесты','фракции','бой','эндгейм','настройки')";
    private const ENUM_OLD = "ENUM('биомы','ресурсы','крафт','персонаж','события','NPC','общие')";

    public function up(): void
    {
        // 1. utf8mb4 (поддержка emoji 4-байта). CONVERT TO пересоздаёт текст-колонки + enum.
        $this->db->query('ALTER TABLE `game_tips` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        // 2. Расширить enum категорий (после конверта — пересоздать с новым набором значений).
        $this->db->query('ALTER TABLE `game_tips` MODIFY `tip_type` ' . self::ENUM_NEW . ' NOT NULL');
    }

    public function down(): void
    {
        // Перед сужением enum — перевести строки новых категорий в 'общие' (иначе ALTER упадёт).
        $this->db->table('game_tips')
            ->whereIn('tip_type', ['земледелие', 'еда', 'квесты', 'фракции', 'бой', 'эндгейм', 'настройки'])
            ->update(['tip_type' => 'общие']);
        $this->db->query('ALTER TABLE `game_tips` MODIFY `tip_type` ' . self::ENUM_OLD . ' NOT NULL');
        $this->db->query('ALTER TABLE `game_tips` CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci');
    }
}
