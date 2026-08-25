<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-176 «Community chat bot» — сырой поток сообщений группового чата сообщества.
 *
 * Одна строка = одна доставленная апдейтом реплика (окно хранения 30 дней, TRANSIENT
 * в Config\WipeManifest). UNIQUE(chat_id, message_id) — идемпотентность повторной
 * доставки Telegram-апдейта (миграция 2026-08-25-100000_Adr176CreateCommunityMessagesTable).
 */
class CommunityMessageModel extends Model
{
    protected $table         = 'community_messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false; // sent_at/created_at ставим явно

    /** @var list<string> */
    protected $allowedFields = [
        'chat_id',
        'message_thread_id',
        'message_id',
        'reply_to_message_id',
        'telegram_user_id',
        'username',
        'text',
        'sent_at',
        'is_question',
        'addressed_to_bot',
        'status',
        'answered_by_id',
        'created_at',
    ];
}
