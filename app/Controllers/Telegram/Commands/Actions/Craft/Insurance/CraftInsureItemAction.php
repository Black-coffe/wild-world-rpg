<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\Insurance;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\ResourceModel;
use App\Services\Player\CraftInsuranceService;
use App\Services\Player\Trade\CraftTypeLabels;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V24 (ADR-056) — NPC-страховой агент: ask + confirm для оформления вечного
 * полиса на строку crafted_items_log.
 *
 *  1. `askForInsurance()` (callback `craftInsure_<log_id>`): валидация (eligible
 *     type, не уже insured, qty>0), расчёт gold через CraftInsuranceService,
 *     проверка баланса. Показывает Confirm.
 *  2. `confirmInsurance()` (callback `confirm_craft_insure_<log_id>`): re-validate
 *     + `CharacterModel::decreaseGold` + `crafted_items_log.insured = 1`.
 *
 * Без task-таймера (instant). Reuse паттерн NpcRepairAction (V23).
 */
class CraftInsureItemAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    private CraftInsuranceService $insurance;
    private CraftRecipes $recipes;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel  = new CraftedItemsLogModel();
        $this->insurance = new CraftInsuranceService();
        $cfg             = config('CraftRecipes');
        $this->recipes   = $cfg instanceof CraftRecipes ? $cfg : new CraftRecipes();
    }

    public function handle(): ServerResponse
    {
        return $this->askForInsurance();
    }

    public function askForInsurance(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }
        if (! $this->insurance->enabled()) {
            return $this->errReply($chatId, 'Страховой агент сейчас не работает.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'craftInsure_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор предмета.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Предмет не найден, уже застрахован или не подлежит страховке.');
        }

        $prices    = $this->loadResourcePrices($ctx['recipe']);
        $gold      = $this->insurance->computePolicyCost($ctx['recipe'], $prices, $ctx['qty']);
        $haveGold  = $this->extractInt($character, 'gold');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $text  = "🛡 *Страховой полис: {$ctx['name_rus']}*\n\n";
        // Категория — человекочитаемой подписью, а не сырым ключом `crafted_items.type`:
        // игрок читал «(transport)» / «(drug)». Словарь один на все экраны лавки.
        $typeRus = CraftTypeLabels::rus((string) $ctx['type']);
        $text .= "Количество: *×{$ctx['qty']}* ({$typeRus})\n";
        $text .= "Тип полиса: *вечный* (до продажи/потери)\n\n";
        $text .= "*Цена:* `{$gold}` 🪙\n";
        $text .= "*В наличии:* `{$haveGold}` 🪙\n\n";
        $text .= "_При смерти эта партия не списывается и не уходит победителю. "
            . "Без полиса штраф смерти для такой техники — это шанс потерять её целиком._";

        if ($haveGold < $gold) {
            $text .= "\n\n❌ _Недостаточно золота._";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '⬅️ К списку', 'callback_data' => 'craftInsuranceList']],
                    [['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
                ],
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => "✅ Заплатить {$gold} 🪙", 'callback_data' => "confirm_craft_insure_{$logId}"]],
                    [
                        ['text' => '⬅️ К списку',  'callback_data' => 'craftInsuranceList'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
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

    public function confirmInsurance(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }
        if (! $this->insurance->enabled()) {
            return $this->errReply($chatId, 'Страховой агент сейчас не работает.');
        }

        $logId = $this->parseLogId($this->callbackQuery->getData(), 'confirm_craft_insure_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор предмета.');
        }

        $ctx = $this->buildContext($character, $logId);
        if ($ctx === null) {
            return $this->errReply($chatId, 'Предмет не найден или уже застрахован.');
        }

        $prices = $this->loadResourcePrices($ctx['recipe']);
        $gold   = $this->insurance->computePolicyCost($ctx['recipe'], $prices, $ctx['qty']);

        $charId   = $this->extractInt($character, 'id');
        $haveGold = $this->extractInt($character, 'gold');
        if ($haveGold < $gold) {
            return $this->errReply($chatId, "Недостаточно золота: нужно {$gold} 🪙, в наличии {$haveGold}.");
        }

        $charModel = $this->characterModel instanceof CharacterModel
            ? $this->characterModel
            : new CharacterModel();
        if (! $charModel->decreaseGold($charId, (float) $gold)) {
            return $this->errReply($chatId, 'Не удалось списать золото.');
        }

        $this->logModel->update($logId, ['insured' => 1]);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Полис оформлен!',
        ]);

        $text  = "🛡 *Полис оформлен!*\n\n"
            . "*{$ctx['name_rus']}* (×{$ctx['qty']}) теперь застрахован.\n"
            . "Списано: *{$gold}* 🪙.\n\n"
            . "_При смерти эта партия не списывается и не уходит победителю. "
            . "Полис действует до продажи или потери предмета._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ К списку',  'callback_data' => 'craftInsuranceList'],
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
     * @return array{log_id:int, name_rus:string, name_eng:string, qty:int, type:string, recipe:array<string,mixed>}|null
     */
    private function buildContext(array|object $character, int $logId): ?array
    {
        $charId = $this->extractInt($character, 'id');
        $eligibleTypes = $this->insurance->eligibleTypes();

        $row = $this->logModel
            ->select('crafted_items_log.id AS log_id, crafted_items_log.quantity AS qty, crafted_items_log.insured, crafted_items.name_rus, crafted_items.name_eng, crafted_items.type')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.id', $logId)
            ->where('crafted_items_log.character_id', $charId)
            ->first();

        if (! is_array($row)) {
            return null;
        }
        $qty = is_numeric($row['qty'] ?? null) ? (int) $row['qty'] : 0;
        if ($qty <= 0) {
            return null;
        }
        $insured = is_numeric($row['insured'] ?? null) ? (int) $row['insured'] : 0;
        if ($insured === 1) {
            return null;
        }
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        if (! in_array($type, $eligibleTypes, true)) {
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
            'qty'      => $qty,
            'type'     => $type,
            'recipe'   => $recipe,
        ];
    }

    /**
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
