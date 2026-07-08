<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Tasks\ActiveTasksService;
use App\Services\World\TextMapService;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-019 Step 3c — UI «Похода»: набор маршрута, выступление, продление, возобновление.
 *
 * Callback-флоу (один class, dispatch по callback_data):
 *   `march`               → экран выбора направления (8 кнопок → march_<dir>_1)
 *   `march_<dir>_<n>`     → экран маршрута + предупреждение (➖/➕5/Выступить/др.направление/назад)
 *   `march_go_<dir>_<n>`  → создать Marching-задачу (status='in_work', task_settings),
 *                           отредактировать сообщение в «🚜 Поход начат…» + карта
 *                           (MarchingTaskHandler дальше редактирует это сообщение каждый тик)
 *   `march_more_<n>`      → продлить идущий поход на n клеток (steps_planned += n) + toast
 *   `march_resume`        → возобновить paused-поход (status='in_work', end_time=now+minutes_per_cell) + toast
 *
 * Экраны редактируют сообщение, на котором нажата кнопка (ADR-018 navTarget), с
 * fallback на новое. Остановку похода обрабатывает {@see CancelMarchAction} (`cancelMarch`).
 */
class MarchAction extends BaseAction
{
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    /** @var array<string, array{int,int}> */
    private const DIRECTIONS = [
        'north'     => [0, -1],
        'south'     => [0, 1],
        'west'      => [-1, 0],
        'east'      => [1, 0],
        'northwest' => [-1, -1],
        'northeast' => [1, -1],
        'southwest' => [-1, 1],
        'southeast' => [1, 1],
    ];

    /** @var array<string, string> */
    private const DIR_LABEL = [
        'north'     => '⬆️ Север',
        'south'     => '⬇️ Юг',
        'west'      => '⬅️ Запад',
        'east'      => '➡️ Восток',
        'northwest' => '↖️ Сев-Запад',
        'northeast' => '↗️ Сев-Восток',
        'southwest' => '↙️ Юго-Запад',
        'southeast' => '↘️ Юго-Восток',
    ];

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
        $charRow        = $this->fetchRow('SELECT id, cell_number FROM characters WHERE telegram_user_id = ? LIMIT 1', [$telegramUserId]);
        if ($charRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        $characterId   = $this->asInt($charRow['id'] ?? 0);
        $charCellNumber = $this->asInt($charRow['cell_number'] ?? 0);

        $data  = (string) $this->callbackQuery->getData();
        $parts = explode('_', $data);

        if ($data === 'march_resume') {
            return $this->resumeMarch($characterId);
        }
        if (count($parts) === 3 && $parts[1] === 'more') {
            return $this->extendMarch($characterId, max(1, (int) $parts[2]));
        }
        if (count($parts) === 4 && $parts[1] === 'go') {
            return $this->startMarch($characterId, $telegramUserId, $charCellNumber, (string) $parts[2], (int) $parts[3], $chatId);
        }
        if (count($parts) === 3 && isset(self::DIRECTIONS[$parts[1]])) {
            return $this->showRouteSetup($characterId, $charCellNumber, (string) $parts[1], (int) $parts[2], $chatId);
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

    private function showRouteSetup(int $characterId, int $charCellNumber, string $dir, int $n, int $chatId): ServerResponse
    {
        if (!isset(self::DIRECTIONS[$dir])) {
            return $this->showDirectionPicker($characterId, $chatId);
        }
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }
        $n = max(1, min($n, $this->maxStepsPerOrder()));

        $aheadBiome = $this->biomeAhead($characterId, $charCellNumber, $dir);
        $hpEst      = round($n * $this->healthCostPerCell(), 2);
        $tiredEst   = round($n * $this->tiredCostPerCell(), 2);
        // Скорость = world.march.cells_per_tick клеток за тик (admin GameSettings; тик =
        // world.march.minutes_per_cell мин, дрожание убрано — stepDueInterval). ETA = ceil(n / perTick) × мин.
        $perTick    = max(1, $this->gsInt('world.march.cells_per_tick', 3));
        $minEst     = (int) ceil($n / $perTick) * $this->minutesPerCell();
        $dirLabel   = self::DIR_LABEL[$dir];

        $text = "🚜 *Поход:* {$dirLabel} ×{$n}\n\n"
            . "Видишь впереди (1 клетка): {$aheadBiome}. Дальше — туман.\n"
            . "В пути возможно (поход тогда прервётся, ты решишь):\n"
            . "  • встреча с игроком → бой / бегство / пройти мимо\n"
            . "  • рейдеры → авто-стычка; тяжёлая рана → привал\n"
            . "  • объект/событие со 100% триггером → отчёт и выбор\n"
            . "  • чужой лагерь на пути → остановишься не доходя\n"
            . "  • кончится выносливость → привал раньше срока\n"
            . "_Прочее (находки, биомы, мелочи) — разгребётся само, отчёт по прибытии._\n\n"
            . "Расход ≈ ❤️{$hpEst}  💤{$tiredEst}  ·  в пути ~{$minEst} мин\n"
            . "_Отряд идёт сам и довольно шустро — темп ровный и от ❤️/💤 не зависит "
            . "(они лишь топливо в пути)._";

        $minus = max(1, $n - 1);
        $plus  = min($n + 5, $this->maxStepsPerOrder());
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
            ],
        ];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    private function startMarch(int $characterId, int $telegramUserId, int $charCellNumber, string $dir, int $n, int $chatId): ServerResponse
    {
        if (!isset(self::DIRECTIONS[$dir])) {
            return $this->showDirectionPicker($characterId, $chatId);
        }
        $blocked = $this->blockReason($characterId, $chatId);
        if ($blocked !== null) {
            return $blocked;
        }
        $n = max(1, min($n, $this->maxStepsPerOrder()));

        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Задача "Поход" не найдена в системе.']);
        }

        $clickedMsgId = $this->callbackQuery->getMessage()->getMessageId();
        $settings = [
            'heading'       => $dir,
            'steps_planned' => $n,
            'steps_done'    => 0,
            'started_cell'  => $charCellNumber,
            'msg_chat_id'   => $chatId,
            'msg_id'        => $clickedMsgId,
            'acc'           => [],
            'log'           => [],
        ];
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        $this->characterTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $telegramUserId,
            'task_id'          => $marchingTaskId,
            'start_time'       => $start->format('Y-m-d H:i:s'),
            'end_time'         => $end->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($settings),
        ]);

        // JIT-подсказка при ПЕРВОМ Походе (one-shot): объясняет логику скорости —
        // темп постоянный, ❤️/💤 = топливо, не двигатель (след вопроса Max Syskov).
        // Defensive: сбой подсказки не должен ломать старт похода.
        try {
            (new \App\Services\Onboarding\OnboardingHintService())->maybeSendFirstMarchHint($characterId, $chatId);
        } catch (\Throwable $e) {
            log_message('error', '[MarchAction] first-march hint: ' . $e->getMessage());
        }

        $map     = $this->renderMap($characterId);
        $dirLabel = self::DIR_LABEL[$dir];
        $text = "🚜 *Поход начат:* {$dirLabel} ×{$n}\n\n{$map}\n"
            . "_Карта обновляется по мере движения — отряд идёт сам и довольно шустро "
            . "(темп ровный, от ❤️/💤 не зависит)._";
        $keyboard = [[['text' => '❌ Остановиться', 'callback_data' => 'cancelMarch']]];
        return $this->editOrSendText($chatId, $text, $keyboard);
    }

    private function extendMarch(int $characterId, int $n): ServerResponse
    {
        $task = $this->activeMarchTask($characterId, 'in_work');
        if ($task === null) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Поход уже завершён — продлевать нечего.',
            ]);
        }
        $s = json_decode($this->asStr($task['task_settings'] ?? '{}', '{}'), true);
        if (!is_array($s)) {
            $s = [];
        }
        $total = max(1, $this->asInt($s['steps_planned'] ?? 1)) + $n;
        $s['steps_planned'] = $total;
        $this->characterTaskModel->update($this->asInt($task['id'] ?? 0), ['task_settings' => json_encode($s)]);

        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => "Поход продлён на {$n} клеток. Всего: {$total}.",
        ]);
    }

    private function resumeMarch(int $characterId): ServerResponse
    {
        $task = $this->activeMarchTask($characterId, 'paused');
        if ($task === null) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Походов на паузе нет.',
            ]);
        }
        $start = new \DateTime();
        $end   = (clone $start)->add($this->stepDueInterval());
        $this->characterTaskModel->update($this->asInt($task['id'] ?? 0), [
            'status'     => 'in_work',
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time'   => $end->format('Y-m-d H:i:s'),
        ]);
        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Поход возобновлён.',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,string> */
    private function dirBtn(string $dir): array
    {
        return ['text' => self::DIR_LABEL[$dir], 'callback_data' => "march_{$dir}_1"];
    }

    /**
     * Интервал «созревания» шага марша: `world.march.minutes_per_cell − 1` мин (см.
     * подробный разбор в {@see \App\TaskHandlers\MarchingTaskHandler::stepDueInterval()}).
     * Worker — everyMinute, спавн phase-locked к тику → −1 убирает лишний тик ожидания
     * (дрожание 1–2 мин/клетку → ровно ~1 мин/клетку). M=1 → PT0M = due на след. тике.
     */
    private function stepDueInterval(): \DateInterval
    {
        $minutes = max(0, $this->minutesPerCell() - 1);

        return new \DateInterval('PT' . $minutes . 'M');
    }

    // ── march-баланс: live-tunable через admin GameSettings `world.march.*`
    //    (ADMIN-TUNABLE BALANCE, ADR-024). Fallback-числа = safe-baseline код-дефолт,
    //    синхронны с MarchingTaskHandler и seed-миграцией 2026-10-08.

    /** Потолок клеток в одном заказе Похода. Fallback 60. */
    private function maxStepsPerOrder(): int
    {
        return max(1, $this->gsInt('world.march.max_steps_per_order', 60));
    }

    /** Минут на крон-тик Похода. Fallback 1. */
    private function minutesPerCell(): int
    {
        return max(1, $this->gsInt('world.march.minutes_per_cell', 1));
    }

    /** Базовый расход ❤️ за клетку (для оценки на экране заказа). Fallback 0.02. */
    private function healthCostPerCell(): float
    {
        return $this->gsFloat('world.march.health_cost_per_cell', 0.02);
    }

    /** Расход 💤 за клетку (для оценки на экране заказа). Fallback 0.5. */
    private function tiredCostPerCell(): float
    {
        return $this->gsFloat('world.march.tired_cost_per_cell', 0.5);
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

    private function biomeAhead(int $characterId, int $charCellNumber, string $dir): string
    {
        $cell = $this->fetchRow('SELECT coordinate_x, coordinate_y FROM map WHERE cell_number = ? LIMIT 1', [$charCellNumber]);
        if ($cell === null) {
            return 'туман';
        }
        [$dx, $dy] = self::DIRECTIONS[$dir];
        $nx = $this->asInt($cell['coordinate_x'] ?? 0) + $dx;
        $ny = $this->asInt($cell['coordinate_y'] ?? 0) + $dy;
        if ($nx < 1 || $nx > 1000 || $ny < 1 || $ny > 1000) {
            return 'край мира';
        }
        $target = $this->fetchRow('SELECT cell_number, biome_id FROM map WHERE coordinate_x = ? AND coordinate_y = ? LIMIT 1', [$nx, $ny]);
        if ($target === null) {
            return 'туман';
        }
        $explored = $this->fetchRow(
            'SELECT id FROM explored_cells WHERE character_id = ? AND map_cell_id = ? LIMIT 1',
            [$characterId, $this->asInt($target['cell_number'] ?? 0)]
        );
        if ($explored === null) {
            return 'туман';
        }
        $biome = $this->fetchRow('SELECT name FROM biomes WHERE id = ? LIMIT 1', [$this->asInt($target['biome_id'] ?? 0)]);
        return $biome !== null ? $this->asStr($biome['name'] ?? 'неизвестно', 'неизвестно') : 'неизвестно';
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

    private function marchingTaskId(): ?int
    {
        $row = $this->fetchRow("SELECT id FROM tasks WHERE name = 'Marching' LIMIT 1", []);
        if ($row === null) {
            return null;
        }
        $id = $this->asInt($row['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @return array<int|string, mixed>|null */
    private function activeMarchTask(int $characterId, string $status): ?array
    {
        $marchingTaskId = $this->marchingTaskId();
        if ($marchingTaskId === null) {
            return null;
        }
        return $this->fetchRow(
            'SELECT id, task_settings FROM character_tasks WHERE character_id = ? AND task_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$characterId, $marchingTaskId, $status]
        );
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

    private function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }
}
