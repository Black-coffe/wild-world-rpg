<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Economy;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\LedgerService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Story chat-requests-batch-06 — экран «🧾 Куда ушло»: отвечает на самый частый вопрос
 * за полгода (Ivan Divan «лога движения средств тоже нету и не понятно нихера»;
 * Max Syskov «У меня исчезло 50% ресурсов…»). Callback `whereItWent`.
 *
 * Вход — безусловная кнопка на «🎒 Инвентарь» рядом со «Складом базы»
 * (UX-DISCOVERABILITY): игрок и замечает пропажу именно там. Только свой персонаж —
 * `LedgerService::entries()` всегда фильтрует по `character_id` вызывающего.
 *
 * Ревью-находка: «🎒 Инвентарь» — PHOTO-сообщение (`InventoryAction::handle()` шлёт
 * `caption`, не `text`), а Telegram `editMessageText` не умеет превратить фото в текст
 * («message has no text to edit») — это ЗАДОКУМЕНТИРОВАННЫЙ случай в
 * {@see MediaSender::editTextOrSend()} (комментарий «клик по photo-сообщению, где
 * нечего редактировать как text»): edit молча падает и `editTextOrSend()` уходит в
 * fallback НОВЫМ сообщением при КАЖДОМ заходе. Раньше здесь ошибочно заявлялось
 * «edit-in-place» — по факту нет, и не может быть, пока источник (Инвентарь) остаётся
 * фото-сообщением. Переключено на прямой `Request::sendMessage()` — тот же паттерн,
 * что у соседнего `BaseStorageListAction` (тоже текстовый экран, тоже открывается из
 * фото-Инвентаря): честно новое сообщение, без бесполезной попытки редактирования.
 */
final class WhereItWentAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        $ledger  = new LedgerService();
        $entries = $ledger->entries($characterId);
        $out     = LedgerService::renderScreen($entries, $ledger->depth(), $ledger->sourcesComplete());

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $out['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $out['buttons']]),
        ]);
    }
}
