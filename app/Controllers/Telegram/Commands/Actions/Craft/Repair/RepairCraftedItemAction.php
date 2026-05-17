<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Repair;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\GameSettings\GameSettingsService;
use Config\CraftRecipes;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S5b (v0.51.188) — 2-этапный ремонт изношенного инструмента.
 *
 * 1. `askForRepair()` (callback `repair_{log_id}`): валидация + расчёт стоимости
 *    (template required_resources × `repair.cost_fraction` из `GameSettings`) +
 *    длительность из `repair.task_duration_minutes`. Показывает Confirm.
 * 2. `confirmRepair()` (callback `confirm_repair_{log_id}`): re-validate + deduct
 *    ресурсы + INSERT `character_tasks` (handler='repair', settings={log_id, recipe}).
 *
 * Восстановление durability — в `RepairCompletionHandler` (taskhandler) при
 * срабатывании worker'а через `tasks:run`.
 */
class RepairCraftedItemAction extends BaseAction
{
    // resourceModel, characterTaskModel, taskModel — inherited из BaseAction
    private CraftedItemsLogModel    $logModel;
    private CharacterResourceModel  $characterResourceModel;
    private GameSettingsService     $settings;
    private CraftRecipes            $recipes;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel               = new CraftedItemsLogModel();
        $this->characterResourceModel = new CharacterResourceModel();
        // characterTaskModel + taskModel + resourceModel — из BaseAction.
        if (! ($this->characterTaskModel instanceof CharacterTaskModel)) {
            $this->characterTaskModel = new CharacterTaskModel();
        }
        if (! ($this->taskModel instanceof TaskModel)) {
            $this->taskModel = new TaskModel();
        }
        if (! ($this->resourceModel instanceof ResourceModel)) {
            $this->resourceModel = new ResourceModel();
        }
        $this->settings = new GameSettingsService();
        $cfg            = config('CraftRecipes');
        $this->recipes  = $cfg instanceof CraftRecipes ? $cfg : new CraftRecipes();
    }

    public function handle(): ServerResponse
    {
        return $this->askForRepair();
    }

    public function askForRepair(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'repair_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор инструмента.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Инструмент не найден или не нуждается в ремонте.');
        }

        $costFraction = $this->settings->get('repair.cost_fraction', 0.50);
        $duration     = $this->settings->get('repair.task_duration_minutes', 15);

        // ceil(template_resources × cost_fraction).
        $cost     = [];
        $recipe   = $ctx['recipe'];
        $template = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
        $costFracFloat = is_numeric($costFraction) ? (float) $costFraction : 0.50;
        foreach ($template as $resName => $qty) {
            if (! is_string($resName) || ! is_numeric($qty)) {
                continue;
            }
            $cost[$resName] = (int) ceil((float) $qty * $costFracFloat);
        }

        // Проверка наличия ресурсов.
        $missing = $this->checkResourceAvailability($this->extractInt($character, 'id'), $cost);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $text  = "🔧 *Ремонт: {$ctx['name_rus']}*\n\n";
        $text .= "Текущая прочность: *{$ctx['cur_dur']}/{$ctx['max_dur']}*\n";
        $text .= "Длительность ремонта: *{$duration} мин.*\n\n";
        $text .= "*Необходимые ресурсы* (50% от полного крафта):\n";
        foreach ($cost as $resName => $needQty) {
            $haveQty = $missing['have'][$resName] ?? 0;
            $mark    = $haveQty >= $needQty ? '✅' : '❌';
            $text   .= "{$mark} *{$resName}*: {$needQty} (в наличии {$haveQty})\n";
        }

        if (! empty($missing['short'])) {
            $text .= "\n_Не хватает ресурсов — собери недостающее, прежде чем чинить._";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⬅️ Назад к списку', 'callback_data' => 'repairToolsList'],
                        ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
                    ],
                ],
            ];
        } else {
            $text .= "\n_Подтверди, чтобы запустить ремонт._";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Подтвердить ремонт', 'callback_data' => "confirm_repair_{$logId}"]],
                    [
                        ['text' => '⬅️ Назад к списку', 'callback_data' => 'repairToolsList'],
                        ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
                    ],
                ],
            ];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function confirmRepair(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'confirm_repair_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор инструмента.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Инструмент не найден или уже отремонтирован.');
        }

        $costFraction = $this->settings->get('repair.cost_fraction', 0.50);
        $duration     = $this->settings->get('repair.task_duration_minutes', 15);
        $duration     = is_numeric($duration) ? max(1, (int) $duration) : 15;

        // Recompute cost (resource prices not cached cross-step — re-validate).
        $cost     = [];
        $recipe   = $ctx['recipe'];
        $template = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
        $costFracFloat = is_numeric($costFraction) ? (float) $costFraction : 0.50;
        foreach ($template as $resName => $qty) {
            if (! is_string($resName) || ! is_numeric($qty)) {
                continue;
            }
            $cost[$resName] = (int) ceil((float) $qty * $costFracFloat);
        }

        $missing = $this->checkResourceAvailability($this->extractInt($character, 'id'), $cost);
        if (! empty($missing['short'])) {
            return $this->errReply($chatId, 'Ресурсов недостаточно для ремонта — состав изменился.');
        }

        // Deduct ресурсы.
        foreach ($cost as $resName => $needQty) {
            // Fresh builder per iteration (CI4 Model where-chain quirk).
            $resRow = (new \App\Models\ResourceModel())->where('name', $resName)->first();
            if (is_object($resRow)) {
                $resRow = $resRow->toArray();
            }
            if (! is_array($resRow) || ! is_numeric($resRow['id'] ?? null)) {
                continue;
            }
            (new \App\Models\CharacterResourceModel())->decreaseResources(
                $this->extractInt($character, 'id'),
                (int) $resRow['id'],
                $needQty
            );
        }

        // Поиск repair task_id.
        $repairTask = $this->taskModel->where('handler_key', 'repair')->first();
        if (! is_array($repairTask)) {
            return $this->errReply($chatId, 'Ошибка конфигурации: task `repair` не найден.');
        }

        $repairTaskIdRaw = $repairTask['id'] ?? 0;
        $repairTaskId    = is_numeric($repairTaskIdRaw) ? (int) $repairTaskIdRaw : 0;
        $startTime       = new DateTime('now');
        $endTime         = (clone $startTime)->modify("+{$duration} minutes");

        // Telegram user id из callback context.
        $tgUserId = $this->extractInt($user, 'id');

        $taskSettings = json_encode([
            'tool_log_id' => $logId,
            'recipe'      => $ctx['name_eng'],
        ], JSON_UNESCAPED_UNICODE);

        $this->characterTaskModel->insert([
            'character_id'      => $this->extractInt($character, 'id'),
            'telegram_user_id'  => $tgUserId,
            'task_id'           => $repairTaskId,
            'status'            => 'in_work',
            'start_time'        => $startTime->format('Y-m-d H:i:s'),
            'end_time'          => $endTime->format('Y-m-d H:i:s'),
            'task_settings'     => $taskSettings,
            'created_at'        => $startTime->format('Y-m-d H:i:s'),
            'updated_at'        => $startTime->format('Y-m-d H:i:s'),
        ]);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Ремонт начат!',
        ]);

        $endStr = $endTime->format('H:i');
        $text   = "🔧 *Ремонт начат*\n\n"
            . "*{$ctx['name_rus']}* — будет готов в *{$endStr}* (через {$duration} мин.).\n\n"
            . "_Я пришлю уведомление, когда ремонт завершится._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ К списку',  'callback_data' => 'repairToolsList'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|object $character
     * @return array{log_id:int, name_rus:string, name_eng:string, cur_dur:int, max_dur:int, recipe:array<string,mixed>}|null
     */
    private function buildContext(array|object $character, int $logId): ?array
    {
        $charId = $this->extractInt($character, 'id');

        $row = $this->logModel
            ->select('crafted_items_log.id AS log_id, crafted_items_log.durability_count AS cur_dur, crafted_items.name_rus, crafted_items.name_eng, crafted_items.durability_count AS max_dur, crafted_items.type')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.id', $logId)
            ->where('crafted_items_log.character_id', $charId)
            ->first();

        if (! is_array($row)) {
            return null;
        }
        if (($row['type'] ?? null) !== 'tool') {
            return null;
        }
        $cur = is_numeric($row['cur_dur'] ?? null) ? (int) $row['cur_dur'] : 0;
        $max = is_numeric($row['max_dur'] ?? null) ? (int) $row['max_dur'] : 0;
        if ($max <= 0 || $cur >= $max) {
            return null; // полный durability — нет смысла ремонтировать
        }

        $nameEng = is_string($row['name_eng'] ?? null) ? $row['name_eng'] : '';
        $recipe  = $this->recipes->get($nameEng);
        if ($recipe === null) {
            return null;
        }

        return [
            'log_id'   => $logId,
            'name_rus' => is_string($row['name_rus'] ?? null) ? $row['name_rus'] : $nameEng,
            'name_eng' => $nameEng,
            'cur_dur'  => $cur,
            'max_dur'  => $max,
            'recipe'   => $recipe,
        ];
    }

    /**
     * @param array<string,int> $cost
     * @return array{have:array<string,int>, short:array<string,int>}
     */
    private function checkResourceAvailability(int $characterId, array $cost): array
    {
        $have  = [];
        $short = [];
        foreach ($cost as $resName => $needQty) {
            $resRow = (new \App\Models\ResourceModel())->where('name', $resName)->first();
            // ResourceModel у F1.4 повертає ResourceEntity — нормалізуємо до array.
            if (is_object($resRow)) {
                $resRow = $resRow->toArray();
            }
            if (! is_array($resRow) || ! is_numeric($resRow['id'] ?? null)) {
                $short[$resName] = $needQty;
                continue;
            }
            $resourceId = (int) $resRow['id'];
            // Fresh builder each iteration (CI4 Model where()-chain не сбрасывается между ->first() вызовами в одном loop'е).
            $charResRow = (new \App\Models\CharacterResourceModel())
                ->where('id_characters', $characterId)
                ->where('id_resources', $resourceId)
                ->first();
            $haveQty = is_array($charResRow) && is_numeric($charResRow['quantity'] ?? null)
                ? (int) $charResRow['quantity']
                : 0;
            $have[$resName] = $haveQty;
            if ($haveQty < $needQty) {
                $short[$resName] = $needQty - $haveQty;
            }
        }
        return ['have' => $have, 'short' => $short];
    }

    /**
     * F1.4 Entity-or-array helper. character/user могут быть `CharacterEntity`
     * (magic getter) или array (legacy). Возвращает int значение по ключу или 0.
     */
    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    private function parseLogId(string $callbackData, string $prefix): int
    {
        if (! str_starts_with($callbackData, $prefix)) {
            return 0;
        }
        $rest = substr($callbackData, strlen($prefix));
        return is_numeric($rest) ? (int) $rest : 0;
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => false,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
