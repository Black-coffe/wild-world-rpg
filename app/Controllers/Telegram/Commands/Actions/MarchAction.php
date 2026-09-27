<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Tasks\ActiveTasksService;
use App\Services\World\MarchService;
use App\Services\World\TextMapService;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-019 Step 3c — UI «Похода»: набор маршрута, выступление, продление, возобновление.
 *
 * Callback-флоу (один class, dispatch по callback_data):
 *   `march`               → экран выбора направления (8 кнопок → march_<dir>_1)
 *   `march_<dir>_<n>`     → экран маршрута + предупреждение (➖/➕5/Выступить/др.направление/назад)
 *   `march_go_<dir>_<n>`  → создать Marching-задачу, отредактировать сообщение в «🚜 Поход начат…»
 *                           + карта (MarchingTaskHandler дальше редактирует это сообщение каждый тик)
 *   `march_more_<n>`      → продлить идущий поход на n клеток (steps_planned += n) + toast
 *   `march_resume`        → возобновить paused-поход + toast
 *
 * W2.N2-03 (ADR-190): логика — {@see MarchService} (тот же сервис зовёт веб `/play`), handler
 * только рендерит исходы. `msg_chat_id`/`msg_id` Похода пишет он же — после своего сообщения.
 *
 * Экраны редактируют сообщение, на котором нажата кнопка (ADR-018 navTarget), с
 * fallback на новое. Остановку похода обрабатывает {@see CancelMarchAction} (`cancelMarch`).
 */
class MarchAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $telegramId = (int) $this->callbackQuery->getFrom()->getId();
        $userRow    = $this->fetchRow('SELECT id FROM telegram_users WHERE telegram_id = ? LIMIT 1', [$telegramId]);
        if ($userRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        $telegramUserId = $this->asInt($userRow['id'] ?? 0);
        $charRow        = $this->fetchRow('SELECT id FROM characters WHERE telegram_user_id = ? LIMIT 1', [$telegramUserId]);
        if ($charRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        $characterId = $this->asInt($charRow['id'] ?? 0);

        $data  = (string) $this->callbackQuery->getData();
        $parts = explode('_', $data);

        if ($data === 'march_resume') {
            return $this->toast((new MarchService())->resume($characterId)['message']);
        }
        if (count($parts) === 3 && $parts[1] === 'more') {
            return $this->toast((new MarchService())->extend($characterId, max(1, (int) $parts[2]))['message']);
        }
        if (count($parts) === 4 && $parts[1] === 'go') {
            return $this->startMarch($characterId, (string) $parts[2], (int) $parts[3], $chatId);
        }
        if (count($parts) === 3 && MarchService::isDirection($parts[1])) {
            return $this->showRouteSetup($characterId, (string) $parts[1], (int) $parts[2], $chatId);
        }
        return $this->showDirectionPicker($characterId, $chatId);
    }

    // ------------------------------------------------------------------ screens

    private function showDirectionPicker(int $characterId, int $chatId): ServerResponse
    {
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }

        $text = "🚜 *Куда выступаем?* Выбери направление.\n\n"
            . "_В походе ты идёшь, пока что-то не потребует решения: встреча с игроком, "
            . "тяжёлая рана, чужой лагерь на пути, край мира, привал по усталости._";
        $keyboard = [
            [$this->dirBtn('northwest'), $this->dirBtn('north'), $this->dirBtn('northeast')],
            [$this->dirBtn('west'), ['text' => '↩️ К карте', 'callback_data' => 'move'], $this->dirBtn('east')],
            [$this->dirBtn('southwest'), $this->dirBtn('south'), $this->dirBtn('southeast')],
        ];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    private function showRouteSetup(int $characterId, string $dir, int $n, int $chatId): ServerResponse
    {
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }
        $p = (new MarchService())->preview($characterId, $dir, $n);
        if (!$p['ok'] || $p['hook'] === null) {
            return $this->showDirectionPicker($characterId, $chatId);
        }
        $n = $p['n'];

        $text = "🚜 *Поход:* {$p['dir_label']} ×{$n}\n\n"
            . "Видишь впереди (1 клетка): {$p['ahead']}. Дальше — туман.\n"
            . "В пути возможно (поход тогда прервётся, ты решишь):\n"
            . "  • встреча с игроком → бой / бегство / пройти мимо\n"
            . "  • рейдеры → авто-стычка; тяжёлая рана → привал\n"
            . "  • объект/событие со 100% триггером → отчёт и выбор\n"
            . "  • чужой лагерь на пути → остановишься не доходя\n"
            . "  • кончится выносливость → привал раньше срока\n"
            . "_Прочее (находки, биомы, мелочи) — разгребётся само, отчёт по прибытии._\n\n"
            . "Расход ≈ ❤️{$p['hp']}  💤{$p['tired']}  ·  в пути ~{$p['eta_minutes']} мин\n"
            . ($p['breakdown_line'] !== '' ? "{$p['breakdown_line']}\n" : '')
            . "_Отряд идёт сам и довольно шустро — темп от ❤️/💤 не зависит "
            . "(они лишь топливо в пути), но зависит от активного транспорта._\n\n"
            . $p['cap_line']
            . $p['hook']['line'];

        $minus = max(1, $n - 1);
        $plus  = min($n + MarchService::EXTEND_STEP, $p['cap']);
        $keyboard = [
            [
                ['text' => '➖', 'callback_data' => "march_{$dir}_{$minus}"],
                ['text' => "×{$n}", 'callback_data' => "march_{$dir}_{$n}"],
                ['text' => '➕5', 'callback_data' => "march_{$dir}_{$plus}"],
            ],
            [['text' => '🚜 Выступить', 'callback_data' => "march_go_{$dir}_{$n}"]],
            [
                ['text' => '🧭 Другое направление', 'callback_data' => 'march'],
                ['text' => '↩️ Назад', 'callback_data' => 'move'],
                $p['hook']['button'],
            ],
        ];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    /**
     * Потолок заказа и его строка — {@see MarchService::clampOrderToCap()} (одна функция на клэмп и текст;
     * точка входа `MarchCapTextTest`).
     *
     * @param array<string,mixed> $profile профиль транспорта
     * @return array{n:int,cap:int,capLine:string}
     */
    public static function clampOrderToCap(int $n, array $profile): array
    {
        return MarchService::clampOrderToCap($n, $profile);
    }

    private function startMarch(int $characterId, string $dir, int $n, int $chatId): ServerResponse
    {
        if (!MarchService::isDirection($dir)) {
            return $this->showDirectionPicker($characterId, $chatId);
        }
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }
        $march   = new MarchService();
        $outcome = $march->start($characterId, $dir, $n);
        if ($outcome['code'] === MarchService::NO_TASK) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $outcome['message']]);
        }
        if (!$outcome['ok']) {
            return $this->showDirectionPicker($characterId, $chatId);
        }

        // JIT-подсказки первого (и первого длинного) Похода — до экрана, как раньше.
        $march->afterStart($characterId, $outcome['n'], $chatId);

        $map  = $this->renderMap($characterId);
        $text = "🚜 *Поход начат:* {$outcome['dir_label']} ×{$outcome['n']}\n\n{$map}\n"
            . "_Карта обновляется по мере движения — отряд идёт сам и довольно шустро "
            . "(темп ровный, от ❤️/💤 не зависит)._";
        $keyboard = [[['text' => '❌ Остановиться', 'callback_data' => 'cancelMarch']]];
        $resp     = $this->editOrSendText($chatId, $text, $keyboard);

        // Тик Похода редактирует это сообщение (то, на котором нажата кнопка).
        $march->attachMessage($characterId, $chatId, (int) $this->callbackQuery->getMessage()->getMessageId());

        return $resp;
    }

    // ------------------------------------------------------------------ helpers

    private function toast(string $text): ServerResponse
    {
        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $text,
        ]);
    }

    /** @return array<string,string> */
    private function dirBtn(string $dir): array
    {
        return ['text' => MarchService::DIR_LABEL[$dir], 'callback_data' => "march_{$dir}_1"];
    }

    /**
     * @see MarchService::vehicleHookBlock()
     *
     * @param array{icon:string,name:string,cells_left:int}|null $active
     * @return array{line:string, button:array{text:string,callback_data:string}}
     */
    public static function vehicleHookBlock(int $characterLevel, int $requiredLevel, ?array $active, bool $vehicleEnabled = true): array
    {
        return MarchService::vehicleHookBlock($characterLevel, $requiredLevel, $active, $vehicleEnabled);
    }

    /**
     * @see MarchService::routeEtaMinutes()
     *
     * @param array<string, array{count:int, per_tick:int}> $segments
     */
    public static function routeEtaMinutes(array $segments, int $minutesPerCell): int
    {
        return MarchService::routeEtaMinutes($segments, $minutesPerCell);
    }

    /** ServerResponse если поход начать нельзя (блокирующая задача / переезд), иначе null. */
    private function blockReason(int $characterId, int $chatId): ?ServerResponse
    {
        if ((new ActiveTasksService())->checkRelocationAndBlock($characterId, $this->callbackQuery->getId(), $chatId)) {
            return Request::emptyResponse();
        }
        if (!$this->checkParallelExecutionAllowed($characterId)) {
            $resp = $this->prepareBlockedTaskResponse('cancelMarch');
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $resp['text'],
                'reply_markup' => $resp['reply_markup'],
                'parse_mode'   => 'Markdown',
            ]);
        }
        return null;
    }

    private function renderMap(int $characterId): string
    {
        $charRow = $this->fetchRow('SELECT * FROM characters WHERE id = ? LIMIT 1', [$characterId]);
        if ($charRow === null) {
            return '';
        }
        try {
            return (new TextMapService())->buildMapOnly($charRow);
        } catch (\Throwable $e) {
            log_message('error', '[MarchAction] map render: ' . $e->getMessage());
            return '';
        }
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

    /**
     * @param array<int, array<int, array<string,string>>> $keyboardRows
     */
    private function editOrSendText(int $chatId, string $text, array $keyboardRows): ServerResponse
    {
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboardRows]),
        ];
        $msgId = $this->callbackQuery->getMessage()->getMessageId();
        try {
            $resp = Request::editMessageText($payload + ['message_id' => $msgId]);
            if ($resp->isOk()) {
                return $resp;
            }
        } catch (\Throwable $e) {
            // fallthrough → новое сообщение
        }
        return Request::sendMessage($payload);
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
