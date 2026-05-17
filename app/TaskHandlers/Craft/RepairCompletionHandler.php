<?php

declare(strict_types=1);

namespace App\TaskHandlers\Craft;

use App\Attributes\HandlerKey;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * S5b (v0.51.188) — completion handler для `repair` task.
 *
 * Trigger: worker берёт row из `character_tasks` где `task_id` = id row'а
 * `tasks` с `handler_key='repair'` (ADR-023 handler_key dispatcher).
 *
 * Поведение:
 *   1. Прочитать `task_settings.tool_log_id`.
 *   2. Найти log + crafted_item.
 *   3. UPDATE `crafted_items_log.durability_count` = template ×
 *      `GameSettings::get('repair.restore_durability_to_percent', 100) / 100`.
 *   4. Mark `character_tasks.status='completed'`.
 *   5. Notify player.
 *
 * Idempotent: повторный run на уже completed task = no-op (status check).
 */
#[HandlerKey(
    key: 'repair',
    displayName: 'Ремонт инструмента',
    description: 'Восстанавливает durability_count инструмента до template-default × GameSettings repair.restore_durability_to_percent. Записывается обратно в crafted_items_log.',
)]
class RepairCompletionHandler extends BaseTaskHandler
{
    private CharacterTaskModel   $characterTaskModel;
    private CraftedItemsLogModel $logModel;
    private CraftedItemsModel    $itemsModel;
    private TelegramUserModel    $telegramUserModel;
    private GameSettingsService  $settings;

    public function __construct()
    {
        $this->characterTaskModel = new CharacterTaskModel();
        $this->logModel           = new CraftedItemsLogModel();
        $this->itemsModel         = new CraftedItemsModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->settings           = new GameSettingsService();
    }

    /**
     * @param array<string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        $taskId = isset($task['id']) && is_numeric($task['id']) ? (int) $task['id'] : 0;
        if ($taskId <= 0) {
            return;
        }

        // Idempotency: если уже completed, ничего не делаем.
        if (($task['status'] ?? '') === 'completed') {
            return;
        }

        $logId = $this->extractToolLogId($task);
        if ($logId <= 0) {
            log_message('error', "[RepairCompletion] task_settings.tool_log_id отсутствует, task_id={$taskId}");
            $this->characterTaskModel->update($taskId, ['status' => 'completed']);
            return;
        }

        $logRow = $this->logModel->find($logId);
        if (! is_array($logRow)) {
            log_message('warning', "[RepairCompletion] crafted_items_log #{$logId} не найден (удалён до завершения ремонта?)");
            $this->characterTaskModel->update($taskId, ['status' => 'completed']);
            return;
        }

        $craftedItemIdRaw = $logRow['crafted_item_id'] ?? null;
        $craftedItemId    = is_numeric($craftedItemIdRaw) ? (int) $craftedItemIdRaw : 0;
        $itemRow          = $craftedItemId > 0 ? $this->itemsModel->find($craftedItemId) : null;
        if (! is_array($itemRow)) {
            log_message('warning', "[RepairCompletion] crafted_items #{$craftedItemId} не найден");
            $this->characterTaskModel->update($taskId, ['status' => 'completed']);
            return;
        }

        $templateDurRaw = $itemRow['durability_count'] ?? null;
        $templateDur    = is_numeric($templateDurRaw) ? (int) $templateDurRaw : 0;
        if ($templateDur <= 0) {
            log_message('warning', "[RepairCompletion] template durability=0 у crafted_item #{$craftedItemId} (не tool?)");
            $this->characterTaskModel->update($taskId, ['status' => 'completed']);
            return;
        }

        $restorePercent = $this->settings->get('repair.restore_durability_to_percent', 100);
        $restorePercent = is_numeric($restorePercent) ? max(1, min(100, (int) $restorePercent)) : 100;
        $newDurability  = (int) max(1, floor($templateDur * $restorePercent / 100));

        $this->logModel->update($logId, ['durability_count' => $newDurability]);
        $this->characterTaskModel->update($taskId, ['status' => 'completed']);

        // Narrow для PHPStan strict: itemRow приходит из CI4 Model with mixed value type.
        $normalized = [];
        foreach ($itemRow as $k => $v) {
            $normalized[(string) $k] = $v;
        }
        $this->notifyPlayer($task, $normalized, $newDurability, $templateDur);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function extractToolLogId(array $task): int
    {
        $rawSettings = $task['task_settings'] ?? null;
        if (! is_string($rawSettings) || $rawSettings === '') {
            return 0;
        }
        $decoded = json_decode($rawSettings, true);
        if (! is_array($decoded)) {
            return 0;
        }
        $val = $decoded['tool_log_id'] ?? null;
        return is_numeric($val) ? (int) $val : 0;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $itemRow
     */
    private function notifyPlayer(array $task, array $itemRow, int $newDurability, int $maxDurability): void
    {
        $tgUserIdRaw = $task['telegram_user_id'] ?? null;
        if (! is_numeric($tgUserIdRaw)) {
            return;
        }
        $userRow = $this->telegramUserModel->find((int) $tgUserIdRaw);
        if (! is_array($userRow) || empty($userRow['telegram_id'])) {
            return;
        }
        $telegramIdRaw = $userRow['telegram_id'];
        if (! is_numeric($telegramIdRaw)) {
            return;
        }

        $name = is_string($itemRow['name_rus'] ?? null) ? $itemRow['name_rus'] : '???';
        $text = "🔧 *Ремонт завершён!*\n\n"
            . "*{$name}* — прочность восстановлена до *{$newDurability}/{$maxDurability}*.\n\n"
            . "_Готов к работе._";

        $this->safeSendMessage((int) $telegramIdRaw, $text, ['parse_mode' => 'Markdown']);
    }
}
