<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Helpers\ResourceIconHelper;
use App\Services\Bases\BaseCheckService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V19 (ADR-050) — превью ремонта робота: текущая/базовая durability, стоимость
 * (золото + ресурсы have/need), кнопка подтверждения. callback `robotRepair_<id>`.
 */
class RobotRepairAction extends RobotRepairBaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->reply($chatId, 'Пользователь не найден или персонаж не определён.');
        }

        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $characterId = (int) $character['id'];

        if (! (new BaseCheckService())->checkBaseStatus($characterId)['hasBase']) {
            return $this->reply($chatId, 'У тебя нет базы — ремонтировать роботов негде.');
        }

        $robotId = $this->robotIdFrom('robotRepair_');
        $ctx     = $this->buildContext($characterId, $robotId);
        if ($ctx['ok'] !== true) {
            return $this->reply($chatId, $ctx['message']);
        }

        $cost    = $ctx['cost'];
        $item    = $ctx['item'];
        $current = $ctx['current'];
        $base    = $ctx['base'];
        $nameRus = is_string($item['name_rus'] ?? null) ? $item['name_rus'] : 'Робот';

        $goldHave = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        $afford   = $this->canAfford($cost, $character, $characterId);

        $text = "🔧 *Ремонт: {$nameRus}*\n\n"
            . "Прочность: *{$current}* / {$base}  (восстановим *+{$cost['restored']}*)\n\n"
            . "*Стоимость ремонта:*\n";

        foreach ($cost['resources'] as $resName => $need) {
            $have = $this->resourceHave($resName, $characterId);
            $mark = $have >= $need ? '✅' : '❌';
            $emoji = ResourceIconHelper::for($resName);
            $text .= "{$mark} {$emoji} {$resName} — {$have} / {$need}\n";
        }
        $goldMark = $goldHave >= $cost['gold'] ? '✅' : '❌';
        $text .= "{$goldMark} 💰 Золото — {$goldHave} / {$cost['gold']}\n";

        if ($afford) {
            $text .= "\nГотово к ремонту. Восстановить прочность? 🔧\n";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '✅ Восстановить', 'callback_data' => 'robotRepairConfirm_' . $robotId],
                ['text' => '◀️ Назад',        'callback_data' => 'activateRobot_' . $robotId],
            ]]];
        } else {
            $text .= "\n__Не хватает ресурсов/золота на ремонт — подкопи и возвращайся.__\n";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '◀️ Назад',     'callback_data' => 'activateRobot_' . $robotId],
            ]]];
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editTextOrSend([
            'chat_id'      => $chatId,
            'message_id'   => $this->callbackQuery->getMessage()->getMessageId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function reply(int $chatId, string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $text]);
    }
}
