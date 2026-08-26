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

    /**
     * Story 76: строка, которую тик имеет право обработать — вопрос (`is_question=1`)
     * ИЛИ прямое обращение к боту без вопросительной формы («Роби помоги»,
     * `addressed_to_bot=1`). Единый источник условия для {@see
     * \App\TaskHandlers\Community\CommunityAutoReplyHandler::handle()} (выборка тика)
     * и {@see \App\Controllers\Admin\CommunityController::openQuestionsBuilder()}
     * (очередь владельца) — до story 76 они повторяли условие раздельно и разошлись:
     * тик забирал `addressed_to_bot=1` (story 57), очередь — нет, эскалированное
     * прямое обращение молча пропадало между выборками. Метод, а не два текстовых
     * копирования — расхождение становится структурно невозможным, а не маловероятным.
     */
    public function whereAddressedOrQuestion(): self
    {
        return $this->groupStart()
            ->where('is_question', 1)
            ->orWhere('addressed_to_bot', 1)
            ->groupEnd();
    }
}
