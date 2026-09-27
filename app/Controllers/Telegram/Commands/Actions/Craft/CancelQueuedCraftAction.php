<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterTaskModel;
use App\Services\Craft\CraftQueueService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * v0.51.129 (community idea #1) — скасування queued craft з refund.
 *
 * Callback: `cancelQueued_<character_task_id>`
 *
 * W2.N3-02 (ADR-190): рендерер ядра {@see CraftQueueService::cancel()} — условное снятие строки
 * (`DELETE … WHERE status='queued'` первым шагом транзакции) и возврат туда, откуда списано
 * (рюкзак/склад по `task_settings.consumed`; у старых строк — рюкзак), золото и предметы —
 * относительной записью. Здесь — разбор callback'а и прежние тексты/кнопки.
 *
 * Refund тільки для 'queued'. Active 'in_work' tasks використовують
 * existing FinishTaskAction (no refund + penalty).
 */
class CancelQueuedCraftAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            return $this->sendError('Неправильный формат отмены.');
        }
        $charTaskId = (int) $parts[1];

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден.');
        }

        // Модель задач экшена передаётся ядру: снимок строки до транзакции читается через неё.
        $tasks = $this->characterTaskModel instanceof CharacterTaskModel ? $this->characterTaskModel : null;
        $out   = (new CraftQueueService($tasks))->cancel((int) $character['id'], $charTaskId);
        if (!$out['ok']) {
            return $this->sendError($out['message']);
        }

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Задача отменена, ресурсы возвращены.',
        ]);

        // #12 edit-in-place (ADR-018): кнопка «🗑 Отменить» жила на сообщении «крафт в очереди» —
        // редактируем его же в «отменено, возвращено» (кнопка-отмены пропадает; fallback на новое).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'       => "🗑 *Задача из очереди отменена*\n\n"
                          . "Возвращены ресурсы для крафта *{$out['name']}* x{$out['qty']} шт.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
            ]]]),
        ]);
    }

    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
