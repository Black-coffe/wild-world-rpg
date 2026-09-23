<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Правка текста совета `ArmorScreen` (см. 2026-10-24-100000_SeedArmorScreenTip.php):
 * кнопка на карточках брони и оружия подписана «Надеть», а не «Одеть» (story
 * bugs-info-0923-05). Идемпотентно — `REPLACE()` на уже применённой подстроке безопасен
 * при повторном запуске.
 */
class FixArmorScreenTipNadet extends Migration
{
    public function up(): void
    {
        $this->db->table('game_tips')
            ->where('title_en', 'ArmorScreen')
            ->set('content', "REPLACE(content, '*Одеть*', '*Надеть*')", false)
            ->update();
    }

    public function down(): void
    {
        $this->db->table('game_tips')
            ->where('title_en', 'ArmorScreen')
            ->set('content', "REPLACE(content, '*Надеть*', '*Одеть*')", false)
            ->update();
    }
}
