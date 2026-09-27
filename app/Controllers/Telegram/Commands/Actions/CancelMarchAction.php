<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\World\MarchService;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-019 Step 3c — остановка «Похода» (callback `cancelMarch`).
 *
 * Находит активную (in_work) или паузнутую (paused) Marching-задачу персонажа и
 * метит её 'completed' — цепочка 1-клеточных задач больше не спавнит следующий
 * шаг. Пройденные/раскрытые клетки остаются (применены при каждом шаге; никакого
 * «всё или ничего», в отличие от старого ExploreTheArea-cancel — тот handler удалён
 * cleanup-тегом после дренажа in-flight задач).
 *
 * W2.N2-03 (ADR-190): остановка — {@see MarchService::stop()} (тот же сервис зовёт веб), handler рендерит исход.
 */
class CancelMarchAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $telegramId = (int) $this->callbackQuery->getFrom()->getId();
        $userRow    = $this->fetchRow('SELECT id FROM telegram_users WHERE telegram_id = ? LIMIT 1', [$telegramId]);
        if ($userRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        $charRow = $this->fetchRow('SELECT id FROM characters WHERE telegram_user_id = ? LIMIT 1', [$this->asInt($userRow['id'] ?? 0)]);
        if ($charRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        $characterId = $this->asInt($charRow['id'] ?? 0);

        $outcome = (new MarchService())->stop($characterId);
        if (!$outcome['ok']) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => $outcome['message'],
            ]);
        }
        $stepsDone = $outcome['steps_done'];

        $text = "🚜 *Поход прерван.* Пройдено `{$stepsDone}` " . MarchService::plural($stepsDone, 'клетку', 'клетки', 'клеток') . ".\n"
            . "_Раскрытые клетки остаются на карте._";
        $keyboard = [[['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']]];

        $payload = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $msgId = $this->callbackQuery->getMessage()->getMessageId();
        try {
            $resp = Request::editMessageText($payload + ['message_id' => $msgId]);
            if ($resp->isOk()) {
                return $resp;
            }
        } catch (\Throwable $e) {
            // fallthrough
        }
        return Request::sendMessage($payload);
    }

    /**
     * @param array<int, int|string> $bind
     * @return array<int|string, mixed>|null
     */
    private function fetchRow(string $sql, array $bind): ?array
    {
        $res = Database::connect()->query($sql, $bind);
        if (!$res instanceof BaseResult) {
            return null;
        }
        $rows = $res->getResultArray();
        $row  = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
