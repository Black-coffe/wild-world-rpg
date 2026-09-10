<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * health-warning-backoff-04 (owner: спам предупреждений о здоровье напрягает) — совет про
 * затухание предупреждений и про тумблер полного отключения в «⚙️ Настройки».
 * Конституция TIPS-COVERAGE (вердикт «да» — тумблер player-facing, см. brief.md спеки).
 * markdown-safe (парные *), utf8mb4, media-off самодостаточен. Idempotent (по title_en).
 */
class SeedHealthWarnToggleTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'HealthWarnBackoffAndToggle';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '⚠️ *Предупреждения о здоровье* теперь не спамят одинаковым сообщением: если ты не '
            . 'реагируешь, следующее предупреждение приходит позже, чем предыдущее. Стоит отреагировать — '
            . 'и частота возвращается к обычной. Если ты не хочешь получать эти сообщения вообще — открой '
            . '*⚙️ Настройки* (раздел *«Ещё»* внизу экрана или команда /settings) — там есть тумблер, '
            . 'который выключает их полностью, включая критические, которые иначе предупреждают о риске '
            . 'смерти персонажа за считанные минуты.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⚠️ Предупреждения о здоровье реже спамят',
            'title_en'   => $titleEn,
            'tip_type'   => 'настройки',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'HealthWarnBackoffAndToggle')->delete();
    }
}
