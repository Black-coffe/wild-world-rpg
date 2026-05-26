<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Repair;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\ResourceModel;
use App\Services\Player\NpcRepairService;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V23 (ADR-055) — NPC-мастер на базе: мгновенный gold-only ремонт tools.
 *
 *  1. `askForRepair()` (callback `npc_repair_<log_id>`): валидация + расчёт gold
 *     (NpcRepairService) + проверка баланса. Показывает Confirm.
 *  2. `confirmRepair()` (callback `confirm_npc_repair_<log_id>`): re-validate +
 *     CharacterModel::decreaseGold + CraftedItemsLogModel::update durability_count = max.
 *
 * Без task-таймера (instant restore) — реюз S5 buildContext/validation паттерна,
 * но другой cost-источник (gold вместо ресурсов) и нет character_tasks insert.
 *
 * Killswitch `npc.repair.enabled` — при off action возвращает ошибку.
 */
class NpcRepairAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    private CharacterResourceModel $characterResourceModel;
    private NpcRepairService $npcRepair;
    private CraftRecipes $recipes;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel               = new CraftedItemsLogModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->npcRepair              = new NpcRepairService();
        $cfg                          = config('CraftRecipes');
        $this->recipes                = $cfg instanceof CraftRecipes ? $cfg : new CraftRecipes();
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

        if (! $this->npcRepair->enabled()) {
            return $this->errReply($chatId, 'NPC-мастер сейчас не работает.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'npc_repair_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор инструмента.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Инструмент не найден или не нуждается в ремонте.');
        }

        $prices  = $this->loadResourcePrices($ctx['recipe']);
        $gold    = $this->npcRepair->computeGoldCost($ctx['recipe'], $prices);
        $haveGold = $this->extractInt($character, 'gold');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $text  = "🛠 *NPC-мастер: {$ctx['name_rus']}*\n\n";
        $text .= "Текущая прочность: *{$ctx['cur_dur']}/{$ctx['max_dur']}*\n";
        $text .= "Восстановление: *мгновенно*\n\n";
        $text .= "*Цена:* `{$gold}` 🪙 (золото)\n";
        $text .= "*В наличии:* `{$haveGold}` 🪙\n\n";
        $text .= "_Мастер берёт золотом и чинит сразу. Без ресурсов и ожидания._";

        if ($haveGold < $gold) {
            $text .= "\n\n❌ _Недостаточно золота. Можно починить ресурсами через обычный ремонт._";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔧 Ремонт ресурсами (S5)', 'callback_data' => "repair_{$logId}"]],
                    [
                        ['text' => '⬅️ Назад к списку', 'callback_data' => 'repairToolsList'],
                        ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
                    ],
                ],
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => "✅ Заплатить {$gold} 🪙", 'callback_data' => "confirm_npc_repair_{$logId}"]],
                    [['text' => '🔧 Или ресурсами (S5)',   'callback_data' => "repair_{$logId}"]],
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

        if (! $this->npcRepair->enabled()) {
            return $this->errReply($chatId, 'NPC-мастер сейчас не работает.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'confirm_npc_repair_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор инструмента.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Инструмент не найден или уже отремонтирован.');
        }

        $prices = $this->loadResourcePrices($ctx['recipe']);
        $gold   = $this->npcRepair->computeGoldCost($ctx['recipe'], $prices);

        $charId   = $this->extractInt($character, 'id');
        $haveGold = $this->extractInt($character, 'gold');
        if ($haveGold < $gold) {
            return $this->errReply($chatId, "Недостаточно золота: нужно {$gold} 🪙, в наличии {$haveGold}.");
        }

        // Списать золото.
        $charModel = $this->characterModel instanceof CharacterModel
            ? $this->characterModel
            : new CharacterModel();
        if (! $charModel->decreaseGold($charId, (float) $gold)) {
            return $this->errReply($chatId, 'Не удалось списать золото.');
        }

        // Instant restore: durability_count → max_dur в crafted_items_log.
        $this->logModel->update($logId, ['durability_count' => $ctx['max_dur']]);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'NPC починил!',
        ]);

        $text  = "🛠 *Готово!*\n\n"
            . "Мастер починил *{$ctx['name_rus']}*: прочность {$ctx['max_dur']}/{$ctx['max_dur']}.\n"
            . "Списано: *{$gold}* 🪙.";

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
     * @param array<string,mixed>|object $character
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
            return null;
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
     * Подтягивает resources.price для всех template-ресурсов рецепта.
     *
     * @param array<array-key,mixed> $recipe
     * @return array<string,int>
     */
    private function loadResourcePrices(array $recipe): array
    {
        $template = $recipe['resources'] ?? [];
        if (! is_array($template)) {
            return [];
        }
        $prices = [];
        foreach (array_keys($template) as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $row = (new ResourceModel())->where('name', $name)->first();
            if (is_object($row)) {
                $row = $row->toArray();
            }
            if (is_array($row) && is_numeric($row['price'] ?? null)) {
                $prices[$name] = (int) $row['price'];
            }
        }
        return $prices;
    }

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
