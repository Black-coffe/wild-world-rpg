<?php

namespace App\Controllers\Telegram\Commands\Actions;

use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-019 Step 3c — остановка «Похода» (callback `cancelMarch`).
 *
 * Находит активную (in_work) или паузнутую (paused) Marching-задачу персонажа и
 * метит её 'completed' — цепочка 1-клеточных задач больше не спавнит следующий
 * шаг. Пройденные/раскрытые клетки остаются (применены при каждом шаге; никакого
 * «всё или ничего», в отличие от старого ExploreTheArea-cancel).
 *
 * `cancelExploration` остаётся отдельным handler'ом `CancelExplorationAction` (старый
 * стоячий explore, депрекейтится в ADR-019 Step 5) — этот handler только для похода.
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

        $marchingTaskId = $this->marchingTaskId();
        $task = $marchingTaskId === null ? null : $this->fetchRow(
            "SELECT id, status, task_settings FROM character_tasks
             WHERE character_id = ? AND task_id = ? AND status IN ('in_work','paused') ORDER BY id DESC LIMIT 1",
            [$characterId, $marchingTaskId]
        );
        if ($task === null) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Активного похода нет.',
            ]);
        }
        $taskId = $this->asInt($task['id'] ?? 0);

        // Атомарно метим completed только если ещё in_work/paused (гонка с Worker'ом).
        Database::connect()->table('character_tasks')
            ->where('id', $taskId)
            ->whereIn('status', ['in_work', 'paused'])
            ->update(['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')]);

        $s = json_decode($this->asStr($task['task_settings'] ?? '{}', '{}'), true);
        $stepsDone = is_array($s) ? max(0, $this->asInt($s['steps_done'] ?? 0)) : 0;

        $text = "🚜 *Поход прерван.* Пройдено `{$stepsDone}` " . $this->plural($stepsDone, 'клетку', 'клетки', 'клеток') . ".\n"
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

    private function marchingTaskId(): ?int
    {
        $row = $this->fetchRow("SELECT id FROM tasks WHERE name = 'Marching' LIMIT 1", []);
        if ($row === null) {
            return null;
        }
        $id = $this->asInt($row['id'] ?? 0);
        return $id > 0 ? $id : null;
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

    private function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n  = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }
        return $many;
    }
}
