<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-148 — единый firehose ВСЕХ прямых действий игрока (callback-кнопки / текст /
 * reply-клавиатура / forceReply / slash-команды). Пишется централизованно из
 * {@see \App\Services\Logging\PlayerActionLogger} в единственной точке
 * {@see \App\Controllers\Telegram\BotController::webhook()} (1 апдейт = 1 действие).
 *
 * ОТДЕЛЬНАЯ от `action_log`: та таблица — курируемые маркеры (онбординг/квесты/воронка,
 * one-shot дедуп по action_name + форензика экономики). Firehose нельзя лить туда, иначе
 * будущая авто-очистка/ротация снесёт маркеры, а COUNT-дедупы деградируют на объёме.
 * Здесь — append-only поток, который безопасно ротировать (ADR-148 §Хранилище).
 *
 * Схема:
 *   - id              BIGINT (firehose растёт — не INT)
 *   - character_id    nullable (действие до создания персонажа, напр. первый /start)
 *   - telegram_user_id nullable (сырой from.id — всегда есть у player-апдейта)
 *   - chat_id         nullable, SIGNED (групповые чаты отрицательны)
 *   - source          ENUM канала ввода
 *   - action_name     нормализованный ключ (callback-сегмент / команда / текст)
 *   - raw_input       полный ввод (callback_data / текст), обрезан до 255
 *   - status          исход действия (ok/error/rejected/unrouted)
 *   - error_text      сообщение исключения / причина отказа (nullable)
 *   - created_at
 *
 * Без FK на characters: append-only телеметрия с nullable-связью; удаление при вайпе —
 * через Config\WipeManifest (PLAYER_DATA), не через каскад. Меньше write-overhead на
 * горячем пути, проще ротация.
 *
 * Индексы: (character_id, created_at) — таймлайн игрока + очистка; created_at — глобальная
 * ротация; action_name — аналитика по типу действия; telegram_user_id — разбор по аккаунту.
 *
 * Классифицирована в Config\WipeManifest как PLAYER_DATA (link=character_id, by=character).
 */
class Adr148CreatePlayerActionLogTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'character_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'telegram_user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'chat_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'null'       => true,
            ],
            'source' => [
                'type'       => 'ENUM',
                'constraint' => ['callback', 'command', 'text', 'forcereply', 'other'],
                'default'    => 'other',
            ],
            'action_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'raw_input' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['ok', 'error', 'rejected', 'unrouted'],
                'default'    => 'ok',
            ],
            'error_text' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['character_id', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->addKey('action_name');
        $this->forge->addKey('telegram_user_id');
        $this->forge->createTable('player_action_log');
    }

    public function down(): void
    {
        $this->forge->dropTable('player_action_log');
    }
}
