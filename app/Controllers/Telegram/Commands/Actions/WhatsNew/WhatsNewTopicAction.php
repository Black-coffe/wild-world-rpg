<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\WhatsNew;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\WhatsNewService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W7b (ADR-065 Part 2) — рендер одной темы каталога. Callback `whatsNew_<topic_key>`
 * (через prefixRoute `whatsNew` → CallbackRoutes; ключ темы = хвост после `whatsNew_`).
 *
 * Показывает content темы (фото + caption) + кнопки «◀️ К каталогу» / «◀️ Перс».
 * Отмечает тему прочитанной (characters.whats_new_seen) → в каталоге пропадает 🆕.
 *
 * Edit-in-place (ADR-018): входит с фото-сообщения каталога → editMessageMedia in-place.
 * Caption берётся из БД (≤1024, media-off-safe — выверено в миграции/тесте).
 */
final class WhatsNewTopicAction extends BaseAction
{
    private const PREFIX = 'whatsNew_';

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

        $data = (string) $this->callbackQuery->getData();
        $key  = str_starts_with($data, self::PREFIX) ? substr($data, strlen(self::PREFIX)) : '';

        $topic = $key !== '' ? $this->service->topicByKey($key) : null;
        if ($topic === null) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => "📚 *Тема не найдена*\n\n_Возможно, она была удалена. Вернись в каталог._",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '◀️ К каталогу', 'callback_data' => 'whatsNewCatalog'],
                ]]]),
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId > 0) {
            $this->service->markSeen($characterId, $key);
        }

        $content  = isset($topic['content']) && is_string($topic['content']) ? $topic['content'] : '';
        $imageRaw = isset($topic['image_path']) && is_string($topic['image_path']) ? $topic['image_path'] : '';
        $imageRel = WhatsNewService::imageRelOrFallback($imageRaw);

        $keyboard = ['inline_keyboard' => [[
            ['text' => '◀️ К каталогу', 'callback_data' => 'whatsNewCatalog'],
            ['text' => '👤 Перс',       'callback_data' => 'character'],
        ]]];

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile(base_url($imageRel)),
            'caption'      => $content,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
