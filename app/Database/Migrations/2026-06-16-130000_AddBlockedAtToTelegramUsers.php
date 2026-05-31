<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Broadcast-гигиена (находка 2026-05-31): отметка, что пользователь заблокировал бота
 * / деактивирован. Ставится при broadcast-отправке с `isOk()=false` + blocked-описанием;
 * снимается при успешной отправке (self-heal, если вернулся). Даёт реальную достижимую
 * аудиторию (≈147 из 893) + основу для будущего skip-оптимизации рассылки.
 *
 * Nullable, default NULL = достижим (текущее поведение для всех).
 */
class AddBlockedAtToTelegramUsers extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('blocked_at', 'telegram_users')) {
            $this->forge->addColumn('telegram_users', [
                'blocked_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                    'comment' => 'Когда бот зафиксировал блокировку/деактивацию (broadcast 403). NULL = достижим.',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('blocked_at', 'telegram_users')) {
            $this->forge->dropColumn('telegram_users', 'blocked_at');
        }
    }
}
