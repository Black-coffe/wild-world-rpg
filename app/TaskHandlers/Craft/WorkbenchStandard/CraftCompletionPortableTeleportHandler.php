<?php

declare(strict_types=1);

namespace App\TaskHandlers\Craft\WorkbenchStandard;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TelegramUserModel;
use App\Services\Player\Craft\PortableTeleportRecipe;
use App\TaskHandlers\BaseTaskHandler;

/**
 * Завершение сборки «📡 Портативный телепорт» (`PortableTeleport`).
 *
 * Выдаёт устройство в `crafted_items_log` с зарядами из {@see PortableTeleportRecipe}
 * (не из шаблона предмета — чтобы админская настройка и реальная выдача не разъезжались),
 * и сразу объясняет, ЧЕМ его применить: экран «📡 Телепорт» вне базы. Без этого игрок
 * получает предмет и не находит кнопку (ровно жалоба, с которой началась работа).
 */
#[HandlerKey(
    key: 'craft_portable_teleport',
    displayName: 'Крафт: Портативный телепорт',
    description: 'Завершение сборки PortableTeleport (WorkbenchStandard, эксклюзив, не через generic_craft).',
)]
class CraftCompletionPortableTeleportHandler extends BaseTaskHandler
{
    protected CharacterModel $characterModel;
    protected CharacterTaskModel $characterTaskModel;
    protected CraftedItemsModel $craftedItemsModel;
    protected CraftedItemsLogModel $craftedItemsLogModel;
    protected TelegramUserModel $telegramUserModel;
    protected PortableTeleportRecipe $recipe;

    public function __construct()
    {
        $this->characterModel       = new CharacterModel();
        $this->characterTaskModel   = new CharacterTaskModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->telegramUserModel    = new TelegramUserModel();
        $this->recipe               = new PortableTeleportRecipe();
    }

    /**
     * @param array<string,mixed> $task строка из character_tasks
     */
    public function handle(array $task = []): void
    {
        $taskRowId = $task['id'] ?? null;
        if (is_numeric($taskRowId)) {
            $this->characterTaskModel->update((int) $taskRowId, ['status' => 'completed']);
        }

        $craftedItem = $this->craftedItemsModel
            ->where('name_eng', PortableTeleportRecipe::ITEM_NAME_ENG)
            ->first();
        if (! is_array($craftedItem)) {
            log_message('error', '[PortableTeleport] предмет PortableTeleport не найден в crafted_items — выдача пропущена.');

            return;
        }

        $characterId = is_numeric($task['character_id'] ?? null) ? (int) $task['character_id'] : 0;
        if ($characterId <= 0) {
            log_message('error', '[PortableTeleport] в задаче нет character_id — выдача пропущена.');

            return;
        }

        $quantity = $this->grantItem($characterId, $task, $craftedItem);

        // Сборка тонкой электроники — как у соседних телепорт-рецептов.
        $this->characterModel->updateAgilityAndIntellect($characterId, 0.05, 0.05);

        $this->notifyUser($task['telegram_user_id'] ?? null, $quantity);
    }

    /**
     * Кладёт устройство в инвентарь. Заряды — из настройки рецепта.
     *
     * @param array<int|string,mixed> $task
     * @param array<int|string,mixed> $craftedItem
     * @return int итоговое количество устройств у игрока (для текста уведомления)
     */
    private function grantItem(int $characterId, array $task, array $craftedItem): int
    {
        $itemId = is_numeric($craftedItem['id'] ?? null) ? (int) $craftedItem['id'] : 0;

        $existing = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $itemId)
            ->first();

        if (is_array($existing)) {
            $qty = is_numeric($existing['quantity'] ?? null) ? (int) $existing['quantity'] : 0;
            $rowId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;

            // Заряды у стака не суммируем сверх базы: устройство одно, зарядов у него
            // ровно столько, сколько даёт рецепт (memory feedback_stack_merge_must_clamp_to_base).
            $durability = is_numeric($existing['durability_count'] ?? null) ? (int) $existing['durability_count'] : 0;
            $this->craftedItemsLogModel->update($rowId, [
                'quantity'         => $qty + 1,
                'durability_count' => max($durability, $this->recipe->charges()),
            ]);

            return $qty + 1;
        }

        $this->craftedItemsLogModel->insert([
            'character_id'      => $characterId,
            'task_id'           => is_numeric($task['task_id'] ?? null) ? (int) $task['task_id'] : null,
            'crafted_item_id'   => $itemId,
            'type'              => self::text($craftedItem['type'] ?? null, 'teleport'),
            'direction_craft'   => self::text($craftedItem['direction_craft'] ?? null, 'teleporter'),
            'crafting_location' => self::text($craftedItem['crafting_location'] ?? null, 'anywhere'),
            'durability_count'  => $this->recipe->charges(),
            'durability_time'   => null,
            'quantity'          => 1,
        ]);

        return 1;
    }

    /** Строковое поле шаблона предмета с запасным значением (mixed → string, phpstan L9). */
    private static function text(mixed $value, string $fallback): string
    {
        return (is_string($value) && $value !== '') ? $value : $fallback;
    }

    private function notifyUser(mixed $telegramUserId, int $quantity): void
    {
        if (! is_numeric($telegramUserId)) {
            return;
        }

        $telegramUser = $this->telegramUserModel->find((int) $telegramUserId);
        if (! is_array($telegramUser) || ! is_numeric($telegramUser['telegram_id'] ?? null)) {
            log_message('error', "[PortableTeleport] не найден TelegramUser ID={$telegramUserId}");

            return;
        }
        $tgId = (int) $telegramUser['telegram_id'];

        $charges = $this->recipe->charges();
        $text = "🏭 *Сборка завершена!*\n\n"
            . "📡 *Портативный телепорт* — в инвентаре (всего: *{$quantity}* шт.).\n"
            . "Зарядов у устройства: *{$charges}*.\n\n"
            . "*Как применить:* находясь где угодно вне базы, жми *📡 Телепорт* — "
            . "и там выбери *📡 Портативный телепорт*. Перенесёт на твою базу мгновенно, "
            . "ждать между применениями не нужно (в отличие от рюкзака — тот раз в час).\n\n"
            . "_Каждое применение тратит один заряд. Кончатся — устройство рассыплется._";

        // Текст самодостаточен (media-off, ADR-020). safeSendPhoto сам деградирует к
        // тексту, если файла картинки на диске нет (resolveLocalPhoto в BaseTaskHandler).
        $this->safeSendPhoto(
            $tgId,
            base_url('uploads/telegram/craft/standard/portable_teleport.jpg'),
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($this->keyboard())]
        );
    }

    /** @return array<string,mixed> */
    private function keyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🔄 Собрать ещё', 'callback_data' => 'portableTeleport2'],
                ],
            ],
        ];
    }
}
