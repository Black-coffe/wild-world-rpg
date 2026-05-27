<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W4 (ADR-063) — preview-экран batch-ремонта. Callback `repairDrone`
 * (без аргументов). Показывает заряд DroneRepair + список ремонтопригодных
 * роботов с per-robot gold-cost + cumulative gold-cost + кнопку
 * «🔧 Починить всех».
 *
 * Lock-state когда:
 *  - Нет DroneRepair в crafted_items_log → инструкция крафтить.
 *  - Charge < drain → подождать перезарядки на базе.
 *  - Нет ремонтопригодных роботов → сообщение «все в исправности».
 *  - Gold < cumulative → показать недостаток.
 *
 * Media-off safe (caption самодостаточен).
 */
class RepairDroneInfoAction extends RepairDroneBaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        $ctx = $this->buildContext($characterId);
        if ($ctx['ok'] !== true) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => "🚁 *Дрон-ремонтник*\n\n{$ctx['message']}",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🛠 К крафту', 'callback_data' => 'standardCraft'],
                    ['text' => '🤖 Роботы',  'callback_data' => 'AllRobots'],
                ]]]),
            ]);
        }

        $charge     = $ctx['droneCharge'];
        $batteryMax = $ctx['droneBatteryMax'];
        $drain      = $ctx['droneDrain'];
        $chargePct  = $batteryMax > 0 ? (int) round($charge * 100 / $batteryMax) : 0;
        $bar        = $this->renderChargeBar($chargePct);
        $robots     = $ctx['robots'];
        $cumulative = $ctx['cumulativeGold'];

        $text  = "🚁 *Дрон-ремонтник*\n\n";
        $text .= "Заряд: {$bar} `{$charge}/{$batteryMax}` ({$chargePct}%)\n";
        $text .= "Один взлёт чинит *всех* роботов с износом — только за чистое золото.\n\n";

        if (empty($robots)) {
            $text .= "_Все твои роботы в полной исправности — дрон-ремонтнику нечего делать._";
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
                    ['text' => '🏠 База',  'callback_data' => 'Base'],
                ]]]),
            ]);
        }

        $text .= "*Ремонтопригодные роботы:*\n";
        foreach ($robots as $r) {
            $text .= "• {$r['nameRus']} — `{$r['current']}/{$r['base']}` за `{$r['goldCost']}` 🪙\n";
        }
        $text .= "\n*Итого: {$cumulative} 🪙*\n";

        $haveGold = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        $goldOk   = $haveGold >= $cumulative;
        $chargeOk = $charge >= $drain;

        $text .= "💰 У тебя: `{$haveGold}` 🪙\n";

        $buttons = [];
        if (! $chargeOk) {
            $text .= "\n🪫 _Заряд ниже {$drain} — вернись на базу для подзарядки (~4 часа до полного)._";
            $buttons[] = ['text' => '🏠 База', 'callback_data' => 'Base'];
            $buttons[] = ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'];
        } elseif (! $goldOk) {
            $need = $cumulative - $haveGold;
            $text .= "\n❌ _Не хватает {$need} 🪙 — подкопи и возвращайся._";
            $buttons[] = ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'];
            $buttons[] = ['text' => '🏠 База',  'callback_data' => 'Base'];
        } else {
            $text .= "\n✅ _Готов к запуску. Один клик — все роботы исправны._";
            $buttons[] = ['text' => '🔧 Починить всех', 'callback_data' => 'repairDroneRun'];
            $buttons[] = ['text' => '🤖 Роботы',       'callback_data' => 'AllRobots'];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [$buttons]]),
        ]);
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
