<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Tasks\ActiveTasksService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S27 — визуализация очереди крафта.
 *
 * Callback: `craftQueue`
 *
 * Показывает craft-pipeline игрока одним экраном: активные крафты (in_work, с ETA)
 * + очередь (queued, FIFO per-recipe). Каждый queued-крафт — с кнопкой ❌ отмены
 * (reuse `cancelQueued_<id>` → CancelQueuedCraftAction, refund). Закрывает техдолг
 * craft-queue (v0.51.129): раньше игрок видел только per-creation уведомление,
 * без сводного «что в очереди».
 */
class ShowCraftQueueAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь или персонаж не найден.',
            ]);
        }

        $queue  = (new ActiveTasksService())->getCraftQueue((int) $character['id']);
        $active = $queue['active'];
        $queued = $queue['queued'];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if ($active === [] && $queued === []) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'         => "📋 *Очередь крафта пуста.*\n\nЗапусти крафт на верстаке — если что-то уже крафтится, новый такой же рецепт встанет в очередь.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ]]]),
            ]);
        }

        $text = "📋 *Очередь крафта*\n"
              . "Активных: *" . count($active) . "*, в очереди: *" . count($queued) . "*\n";

        if ($active !== []) {
            $text .= "\n🔨 *Сейчас крафтится:*\n";
            foreach ($active as $a) {
                $eta   = $a['seconds_left'] > 0 ? $this->formatDuration($a['seconds_left']) : 'вот-вот';
                $text .= "• {$a['name']} ×{$a['qty']} — готово через {$eta}\n";
            }
        }

        $keyboardRows = [];
        if ($queued !== []) {
            $text .= "\n⏳ *В очереди* (активируются по мере завершения):\n";
            $pos = 1;
            foreach ($queued as $q) {
                $text .= "{$pos}. {$q['name']} ×{$q['qty']}\n";
                $keyboardRows[] = [[
                    'text'          => "❌ Отменить: {$q['name']} ×{$q['qty']}",
                    'callback_data' => "cancelQueued_{$q['charTaskId']}",
                ]];
                $pos++;
            }
            $text .= "\n_Отмена очереди возвращает списанные ресурсы._";
        }

        $keyboardRows[] = [
            ['text' => '🔄 Обновить',        'callback_data' => 'craftQueue'],
            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
        ];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboardRows]),
        ]);
    }

    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return "{$h} ч. {$m} мин.";
        }
        if ($m > 0) {
            return "{$m} мин.";
        }
        return 'меньше минуты';
    }
}
