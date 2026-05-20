<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Notifications\MediaSender;
use Config\CraftRecipes;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * v0.51.129 (community idea #1) — скасування queued craft з refund.
 *
 * Callback: `cancelQueued_<character_task_id>`
 *
 * Поведінка:
 *   - Знайти task у статусі 'queued' з відповідним character_id (anti-spoof).
 *   - Refund resources, crafted_items, gold (per recipe × quantity).
 *   - DELETE task row (не 'interrupted' — не потрібно зберігати слід queued).
 *
 * Refund тільки для 'queued'. Active 'in_work' tasks використовують
 * existing FinishTaskAction (no refund + penalty).
 */
class CancelQueuedCraftAction extends BaseAction
{
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        // $resourceModel — inherited protected from BaseAction (already initialized).
    }

    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            return $this->sendError('Неправильный формат отмены.');
        }
        $charTaskId = (int) $parts[1];

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден.');
        }

        $task = $this->characterTaskModel
            ->where('id', $charTaskId)
            ->where('character_id', $character['id'])
            ->where('status', 'queued')
            ->first();
        if (!$task) {
            return $this->sendError('Задача в очереди не найдена или уже активирована.');
        }

        $settings = json_decode((string) ($task['task_settings'] ?? '{}'), true);
        $recipeKey = is_array($settings) ? ($settings['recipe'] ?? null) : null;
        $quantity  = is_array($settings) ? max(1, (int) ($settings['quantity'] ?? 1)) : 1;

        if (!is_string($recipeKey)) {
            return $this->sendError('Поврежденные данные задачи.');
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($recipeKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестный рецепт: {$recipeKey}");
        }

        // Транзакція: refund + delete row
        $db = \Config\Database::connect();
        $db->transStart();

        $this->refundResources((int) $character['id'], $recipe['resources'] ?? [], $quantity);
        $this->refundCraftedItems((int) $character['id'], $recipe['crafted_items'] ?? [], $quantity);

        $goldRefund = (int) ($recipe['gold_required'] ?? 0) * $quantity;
        if ($goldRefund > 0) {
            $this->characterModel->where('id', $character['id'])->set('gold', "gold + {$goldRefund}", false)->update();
        }

        $this->characterTaskModel->delete($charTaskId);

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[CancelQueuedCraft] транзакция упала task_id={$charTaskId} char_id={$character['id']}");
            return $this->sendError('Ошибка при отмене. Попробуйте ещё раз.');
        }

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Задача отменена, ресурсы возвращены.',
        ]);

        // #12 edit-in-place (ADR-018): кнопка «🗑 Отменить» жила на сообщении «крафт в очереди» —
        // редактируем его же в «отменено, возвращено» (кнопка-отмены пропадает; fallback на новое).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'       => "🗑 *Задача из очереди отменена*\n\n"
                          . "Возвращены ресурсы для крафта *{$recipe['item_name_rus']}* x{$quantity} шт.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
            ]]]),
        ]);
    }

    /** @param array<string,int> $reqs */
    private function refundResources(int $charId, array $reqs, int $qty): void
    {
        foreach ($reqs as $resName => $perOne) {
            $refund   = $perOne * $qty;
            $resource = $this->resourceModel->where('name', $resName)->first();
            if (!$resource) {
                continue;
            }
            $existing = $this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', $resource['id'])
                ->first();
            if ($existing) {
                $newQty = (int) $existing['quantity'] + $refund;
                $this->characterResourceModel->update($existing['id'], ['quantity' => $newQty]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters' => $charId,
                    'id_resources'  => $resource['id'],
                    'quantity'      => $refund,
                ]);
            }
        }
    }

    /** @param array<string,int> $reqs */
    private function refundCraftedItems(int $charId, array $reqs, int $qty): void
    {
        foreach ($reqs as $itemEn => $perOne) {
            $refund = $perOne * $qty;
            $item   = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                continue;
            }
            $existing = $this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', $item['id'])
                ->first();
            if ($existing) {
                $newQty = (int) $existing['quantity'] + $refund;
                $this->craftedItemsLogModel->update($existing['id'], ['quantity' => $newQty]);
            } else {
                $this->craftedItemsLogModel->insert([
                    'character_id'      => $charId,
                    'task_id'           => null,
                    'crafted_item_id'   => $item['id'],
                    'type'              => $item['type'] ?? null,
                    'direction_craft'   => $item['direction_craft'] ?? null,
                    'crafting_location' => $item['crafting_location'] ?? null,
                    'durability_count'  => $item['durability_count'] ?? null,
                    'durability_time'   => null,
                    'quantity'          => $refund,
                ]);
            }
        }
    }

    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
