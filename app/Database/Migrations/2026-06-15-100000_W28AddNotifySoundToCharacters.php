<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W28 (ADR-083) — per-character override «звук о завершении задач».
 *
 * characters.notify_sound (0/1, default 0). Smysl при активном killswitch
 * `notifications.silent_threshold.enabled`:
 *   0 = рутинные task-completions (добыча/крафт/стройка/разведка) приходят
 *       ТИХО (disable_notification=true: видны в чате, но без звука/вибро) — это
 *       редизайн по умолчанию;
 *   1 = игрок явно вернул звук — рутинные уведомления приходят со звуком, как раньше.
 *
 * Default 0 → при выключенном killswitch (dormant на проде) 0 изменений для всех
 * игроков (policy при OFF возвращает «не тихо» независимо от колонки).
 */
class W28AddNotifySoundToCharacters extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE characters ADD COLUMN notify_sound TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE characters DROP COLUMN IF EXISTS notify_sound');
    }
}
