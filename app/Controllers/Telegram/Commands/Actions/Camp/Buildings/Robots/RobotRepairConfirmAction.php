<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Services\Bases\BaseCheckService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V19 (ADR-050) — подтверждение ремонта: списание ресурсов+золота, восстановление
 * durability текущего робота до базового. callback `robotRepairConfirm_<id>`.
 */
class RobotRepairConfirmAction extends RobotRepairBaseAction
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

        $robotId = $this->robotIdFrom('robotRepairConfirm_');
        $ctx     = $this->buildContext($characterId, $robotId);
        if ($ctx['ok'] !== true) {
            return $this->reply($chatId, $ctx['message']);
        }

        $cost   = $ctx['cost'];
        $item   = $ctx['item'];
        $logRow = $ctx['logRow'];
        $base   = $ctx['base'];

        if (! $this->canAfford($cost, $character, $characterId)) {
            return $this->reply($chatId, 'Недостаточно ресурсов или золота для ремонта.');
        }

        $logId = isset($logRow['id']) && is_numeric($logRow['id']) ? (int) $logRow['id'] : 0;
        if ($logId === 0) {
            return $this->reply($chatId, 'Ошибка: запись о роботе не найдена.');
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Списание золота (builder decrement; canAfford гарантирует неотрицательность).
        if ($cost['gold'] > 0) {
            $db->table('characters')->where('id', $characterId)->decrement('gold', $cost['gold']);
        }
        // Списание ресурсов.
        foreach ($cost['resources'] as $resName => $need) {
            $res = $this->charResources->getResourceByNameAndCharacterId($resName, $characterId);
            if (! is_array($res) || ! isset($res['id']) || ! is_numeric($res['id'])) {
                continue;
            }
            $charRes = $this->charResources->where('id_characters', $characterId)->where('id_resources', (int) $res['id'])->first();
            if (! is_array($charRes)) {
                continue;
            }
            $crId = isset($charRes['id']) && is_numeric($charRes['id']) ? (int) $charRes['id'] : 0;
            if ($crId === 0) {
                continue;
            }
            $have = isset($charRes['quantity']) && is_numeric($charRes['quantity']) ? (int) $charRes['quantity'] : 0;
            $this->charResources->update($crId, ['quantity' => max(0, $have - $need)]);
        }
        // Восстановление durability текущего робота до базового.
        $this->logModel->update($logId, ['durability_count' => $base]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[RobotRepairConfirm] транзакция упала для character {$characterId}, robot {$robotId}");
            return $this->reply($chatId, 'Ошибка при ремонте. Попробуй ещё раз.');
        }

        $nameRus = is_string($item['name_rus'] ?? null) ? $item['name_rus'] : 'Робот';
        $text = "🔧 *Ремонт завершён!*\n\n"
            . "*{$nameRus}* восстановлен: прочность снова *{$base}*.\n\n"
            . "Можно снова запускать его в дело! 🤖";

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
            ['text' => '🏠 База',   'callback_data' => 'Base'],
        ]]];

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
