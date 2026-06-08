<?php

namespace App\TaskHandlers\Built;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterBuildingModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\I18n\Time;

/**
 * v0.51.22 (F2.9 batch-4): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Bonus: PSR-4 namespace casing fixed (`app\` → `App\`).
 * Telegram lazy-init, Request::sendMessage → safeSendMessage.
 * `handle(array $task)` → `handle(array $task = []): void`.
 */
#[HandlerKey(
    key: 'base_relocation',
    displayName: 'Перенос базы (без построек)',
    description: 'Завершение задачи BaseRelocation: перенос координат базы без переноса построек.',
)]
class BaseRelocationCompletionHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $claimedCellModel;
    protected $characterBuildingModel;
    protected $telegramUserModel;

    public function __construct()
    {
        $this->characterModel        = new CharacterModel();
        $this->characterTaskModel    = new CharacterTaskModel();
        $this->claimedCellModel      = new ClaimedCellModel();
        $this->characterBuildingModel= new CharacterBuildingModel();
        $this->telegramUserModel     = new TelegramUserModel();
    }

    /**
     * Метод handle($task) вызывается воркером, коли BaseRelocation end_time підійшов.
     *
     * @param array<string,mixed> $task — масив з character_tasks.
     */
    public function handle(array $task = []): void
    {
        $db = \Config\Database::connect();
        $db->reconnect();

        // 1) Проверяем, что персонаж существует
        $characterId = $task['character_id'] ?? null;
        if (!$characterId) {
            log_message('error', "BaseRelocationCompletionHandler: нет character_id в задаче ID {$task['id']}");
            return;
        }
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "BaseRelocationCompletionHandler: персонаж ID {$characterId} не найден.");
            return;
        }

        // 2) Ищем ту же задачу в character_tasks: статус должен быть 'in_work', end_time <= now()
        $taskRow = $this->characterTaskModel
            ->where('id', $task['id'])
            ->where('status', 'in_work')
            ->first();

        if (!$taskRow) {
            log_message('debug', "BaseRelocationCompletionHandler: Задача ID {$task['id']} не найдена или не 'in_work'. Прерываем.");
            return;
        }

        // 3) Сравниваем end_time с текущим временем
        $now     = Time::now();
        $endTime = new Time($taskRow['end_time'] ?? 'now');

        if ($endTime->isAfter($now)) {
            // Значит, время ещё не наступило, рано завершать
            log_message('debug', "BaseRelocationCompletionHandler: Задача ID {$task['id']} ещё не достигла end_time.");
            return;
        }

        // 4) Если end_time прошёл → переводим задачу в completed
        $this->characterTaskModel->update($taskRow['id'], [
            'status' => 'completed'
        ]);

        // ADR-102: сносим ТОЛЬКО ту базу, для которой запускали снос (base_cell
        // зафиксирован в task_settings). Legacy in-flight задачи без base_cell →
        // старое поведение (снос всех баз персонажа) с предупреждением в лог.
        $baseCell = $this->resolveBaseCell($taskRow);

        $buildingsQuery = $this->characterBuildingModel->where('character_id', $characterId);
        $cellsQuery     = $this->claimedCellModel->where('character_id', $characterId);
        if ($baseCell !== null) {
            $buildingsQuery->where('map_cell_id', $baseCell);
            $cellsQuery->where('map_cell_id', $baseCell);
        } else {
            $cidLog = is_numeric($characterId) ? (int) $characterId : 0;
            $tidLog = is_numeric($taskRow['id'] ?? null) ? (int) $taskRow['id'] : 0;
            log_message('warning', "BaseRelocationCompletionHandler: task {$tidLog} без base_cell — снос всех баз персонажа {$cidLog} (legacy fallback).");
        }

        // 5) Удаляем строения этой базы
        $buildingsQuery->delete();

        // 6) Удаляем запись базы из claimed_cells
        $cellsQuery->delete();

        // 6b) Чистим сирот (маяки телепорта снесённой базы)
        if ($baseCell !== null) {
            (new \App\Models\TeleportBeaconModel())
                ->where('character_id', $characterId)
                ->where('map_cell_id', $baseCell)
                ->delete();
        }

        // 7) Уведомляем игрока
        $this->notifyUser($character);
    }

    /**
     * ADR-102: клетка базы, для которой запускался плановый снос (из task_settings.base_cell).
     * null — legacy-задача без base_cell.
     *
     * @param array<string,mixed> $taskRow
     */
    private function resolveBaseCell(array $taskRow): ?int
    {
        $raw = $taskRow['task_settings'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['base_cell']) && is_numeric($decoded['base_cell'])) {
                return (int) $decoded['base_cell'];
            }
        }
        return null;
    }

    /**
     * Шлёт сообщение: "Переезд завершён, базу можно разбить заново" и т.д.
     */
    private function notifyUser(array|\App\Entities\CharacterEntity $character): void
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser || empty($telegramUser['telegram_id'])) {
            log_message('error', "BaseRelocationCompletionHandler: не найден telegram_id у персонажа ID {$character['id']}");
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $text = "🛠 *Планируемый снос базы завершён!* 🏚\n\n"
            . "Твоя старая база успешно демонтирована, все здания сохранены \"в памяти\". "
            . "Теперь ты можешь в любой момент разбить *новую* базу в удобной локации.\n\n"
            . "Но будь осторожен: смерть без базы приведёт к потере 50% ресурсов!\n\n"
            . "Удачи на новом месте!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Разбить новую базу', 'callback_data' => 'Camp'],
                    ['text' => 'Действия', 'callback_data' => 'characterActions']
                ],
            ]
        ];

        $this->safeSendMessage($chatId, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}