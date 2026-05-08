<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Идея #14 (Yupirex, 13.02.2025): toggle на отключение медиа в сообщениях бота.
 *
 * "медиа делают сообщения слишком длинными и интересны первые пару дней,
 *  потом приедаются и внимание на них не обращаешь".
 *
 * Флаг 0=images on (default), 1=text-only mode. Используется
 * `App\Services\Notifications\MediaSender::sendPhotoOrText()`.
 *
 * Toggle через typed-command `media_off` / `media_on` в GenericmessageCommand
 * (паттерн как `preferred_map_type` через `beautiful_map`/`accurate_map`).
 */
class AddDisableMediaFlag extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('characters', [
            'disable_media' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'comment'    => 'Idea #14: 1 = text-only mode (no sendPhoto), 0 = images on',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('characters', 'disable_media');
    }
}
