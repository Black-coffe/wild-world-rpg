<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tips-overhaul Фаза A1 (ADR-038) — расширение категорий советов.
 *
 * Добавляет в ENUM `game_tips.tip_type` новые группы под механики V1-V13:
 *   земледелие, еда, квесты, фракции, бой, эндгейм, настройки
 * (к существующим: биомы, ресурсы, крафт, персонаж, события, NPC, общие).
 *
 * Расширение enum новыми значениями не затрагивает существующие строки.
 */
class TipsAExtendCategories extends Migration
{
    private const ENUM_NEW = "ENUM('биомы','ресурсы','крафт','персонаж','события','NPC','общие','земледелие','еда','квесты','фракции','бой','эндгейм','настройки')";
    private const ENUM_OLD = "ENUM('биомы','ресурсы','крафт','персонаж','события','NPC','общие')";

    public function up(): void
    {
        $this->db->query('ALTER TABLE `game_tips` MODIFY `tip_type` ' . self::ENUM_NEW . ' NOT NULL');
    }

    public function down(): void
    {
        // Перед сужением enum — перевести строки новых категорий в 'общие' (иначе ALTER упадёт).
        $this->db->table('game_tips')
            ->whereIn('tip_type', ['земледелие', 'еда', 'квесты', 'фракции', 'бой', 'эндгейм', 'настройки'])
            ->update(['tip_type' => 'общие']);
        $this->db->query('ALTER TABLE `game_tips` MODIFY `tip_type` ' . self::ENUM_OLD . ' NOT NULL');
    }
}
