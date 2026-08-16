<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\WhatsNew;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\WhatsNewService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W7b (ADR-065 Part 2) — entry-point каталога «📚 Что нового». Callback `whatsNewCatalog`.
 *
 * Всегда доступен с экрана Перс (правило #4 UX-discoverability). Показывает список тем
 * (🆕 — непрочитанные для этого персонажа) + кнопки перехода в тему.
 *
 * Edit-in-place (ADR-018): вход с текстового Перс-сообщения → editMessageMedia не сможет
 * (нет медиа) → graceful fallback на новое фото-сообщение (MediaSender). Дальнейшая
 * навигация catalog↔topic — уже in-place (фото-сообщение).
 *
 * Caption media-off-safe + ≤1024 (6 тем компактно).
 */
final class WhatsNewCatalogAction extends BaseAction
{
    private WhatsNewService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new WhatsNewService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if (! $this->service->isEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "📚 *«Что нового» временно недоступно*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $topics      = $this->service->allTopics();

        if (empty($topics)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "📚 *Что нового*\n\n_Пока пусто — раздел наполняется._",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '◀️ Перс', 'callback_data' => 'character'],
                ]]]),
            ]);
        }

        $seen = $characterId > 0 ? $this->service->seenKeys($characterId) : [];

        $text  = "📚 *Что нового*\n\n";
        $text .= "Краткий справочник по механикам игры. 🆕 — то, что ты ещё не открывал.\n\n";

        $rows    = [];
        $rowPack = [];
        foreach ($topics as $topic) {
            $key     = isset($topic['topic_key']) && is_string($topic['topic_key']) ? $topic['topic_key'] : '';
            $title   = isset($topic['title']) && is_string($topic['title']) ? $topic['title'] : $key;
            $summary = isset($topic['summary']) && is_string($topic['summary']) ? $topic['summary'] : '';
            if ($key === '') {
                continue;
            }
            $isNew    = ! in_array($key, $seen, true);
            $marker   = $isNew ? '🆕 ' : '• ';
            $text    .= $marker . "*{$title}*" . ($summary !== '' ? " — {$summary}" : '') . "\n";

            $btnLabel = ($isNew ? '🆕 ' : '') . $title;
            $rowPack[] = ['text' => $btnLabel, 'callback_data' => "whatsNew_{$key}"];
            if (count($rowPack) === 2) {
                $rows[]  = $rowPack;
                $rowPack = [];
            }
        }
        if (! empty($rowPack)) {
            $rows[] = $rowPack;
        }
        $rows[] = [['text' => '◀️ Перс', 'callback_data' => 'character']];

        $imageRel = WhatsNewService::imageRelOrFallback('uploads/telegram/character/whatsnew/catalog.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile(base_url($imageRel)),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }
}
