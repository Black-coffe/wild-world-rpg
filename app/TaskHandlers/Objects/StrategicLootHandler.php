<?php

declare(strict_types=1);

namespace App\TaskHandlers\Objects;

use App\Models\BiomeWorldObjectMapModel;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;

/**
 * v0.51.109 — Strategic objects content phase (Bunker / Technopark / GhostCity).
 *
 * Generic auto-loot handler з discovery_tools check.
 * Усі 3 strategic objects (Бункер, Технопарк, Город-призрак) використовують
 * цей handler. Object-specific config (name/loot/photo) береться з world_objects DB.
 *
 * Pattern combines:
 *  - ToolkitHandler (auto-loot processDiscovery)
 *  - ClosedWarehouseHandler (discovery_tools check + insufficient-tools fallback)
 *  - Rich announcement з photo (per-object via convention path).
 *
 * Photo convention: `public/uploads/telegram/objects/{name_en_lowercase}.jpg`.
 * Якщо файл відсутній — Telegram safeSendPhoto fallback'ить на text-only.
 */
class StrategicLootHandler extends BaseObjectHandler implements ObjectHandlerInterface
{
    private TelegramUserModel $telegramUserModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private CraftedItemsModel $craftedItemsModel;
    private BiomeWorldObjectMapModel $biomeWorldObjectMapModel;
    private ResourceModel $resourceModel;
    private CharacterModel $characterModel;

    public function __construct()
    {
        $this->telegramUserModel        = new TelegramUserModel();
        $this->craftedItemsLogModel     = new CraftedItemsLogModel();
        $this->craftedItemsModel        = new CraftedItemsModel();
        $this->biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $this->resourceModel            = new ResourceModel();
        $this->characterModel           = new CharacterModel();
    }

    public function handle($object, $cell, $character): void
    {
        $requiredTools = json_decode($object['discovery_tools'] ?? '[]', true) ?: [];

        // Tool check (якщо потребно)
        if (!empty($requiredTools[0])) {
            foreach ($requiredTools[0] as $itemName => $quantity) {
                $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemName, $character['id']);
                if (!$item || $item['quantity'] < $quantity) {
                    $this->sendInsufficientToolsMessage($object, $character, $requiredTools[0]);
                    return;
                }
            }
        }

        // XP bump за стратегічну знахідку (більше ніж warehouse — strategic value).
        $this->characterModel->update($character['id'], [
            'experience' => $character['experience'] + 0.5,
            'strength'   => $character['strength'] + 0.15,
            'agility'    => $character['agility'] + 0.05,
            'intellect'  => $character['intellect'] + 0.20,
        ]);

        // Auto-loot.
        $contents = json_decode($object['contents'] ?? '[]', true) ?: [];
        $foundItems = $this->awardContents($character, $contents[0] ?? []);

        // Mark cleared.
        $biomeMapRow = $this->biomeWorldObjectMapModel
            ->where('world_object_id', $object['world_object_id'] ?? $object['id'])
            ->where('map_id', $object['map_id'] ?? 0)
            ->first();
        if ($biomeMapRow && isset($biomeMapRow['id'])) {
            $this->biomeWorldObjectMapModel->updateStatus((int) $biomeMapRow['id'], 'cleared');
        }

        // Rich announcement.
        $this->sendDiscoveryMessage($object, $character, $foundItems);
    }

    /**
     * @param array<string, mixed> $contents
     * @return array{resources: array<string, int>, crafted_items: array<string, int>}
     */
    private function awardContents(array $character, array $contents): array
    {
        $foundResources = [];
        $foundCrafted   = [];

        if (isset($contents['resources']) && is_array($contents['resources'])) {
            foreach ($contents['resources'] as $resourceName => $maxAmount) {
                $randomAmount = rand(1, max(1, (int) $maxAmount));
                $this->resourceModel->addOrIncreaseResource($character['id'], $resourceName, $randomAmount);
                $foundResources[$resourceName] = $randomAmount;
            }
        }

        if (isset($contents['crafted_items']) && is_array($contents['crafted_items'])) {
            foreach ($contents['crafted_items'] as $itemName => $maxAmount) {
                $randomAmount = rand(1, max(1, (int) $maxAmount));
                $itemRow = $this->craftedItemsModel->getRowByName($itemName);
                if ($itemRow && isset($itemRow['id'])) {
                    $this->craftedItemsModel->addOrIncreaseItem(
                        (int) $character['id'],
                        $itemName,
                        $randomAmount
                    );
                    $foundCrafted[$itemName] = $randomAmount;
                }
            }
        }

        return ['resources' => $foundResources, 'crafted_items' => $foundCrafted];
    }

    /**
     * @param array{resources: array<string, int>, crafted_items: array<string, int>} $foundItems
     */
    private function sendDiscoveryMessage(array $object, array $character, array $foundItems): void
    {
        $tg = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tg || empty($tg['telegram_id'])) {
            log_message('error', "StrategicLootHandler: no telegram_id for character_id={$character['id']}");
            return;
        }

        $name        = $object['name'] ?? 'Стратегический объект';
        $description = $object['description'] ?? '';

        $msg  = "🏛 *Найден стратегический объект!*\n";
        $msg .= "📍 *{$name}*\n\n";
        if ($description !== '') {
            $msg .= "_{$description}_\n\n";
        }

        if (!empty($foundItems['resources'])) {
            $msg .= "📦 *Добытые ресурсы:*\n";
            foreach ($foundItems['resources'] as $resName => $qty) {
                $resRow = $this->resourceModel->where('name_en', $resName)->first();
                $resNameRus = is_array($resRow) && isset($resRow['name']) && is_string($resRow['name'])
                    ? $resRow['name']
                    : $resName;
                $msg .= "— *{$resNameRus}*: {$qty} шт.\n";
            }
            $msg .= "\n";
        }

        if (!empty($foundItems['crafted_items'])) {
            $msg .= "🛠 *Добытые предметы:*\n";
            foreach ($foundItems['crafted_items'] as $itemName => $qty) {
                $itemRow = $this->craftedItemsModel->getRowByName($itemName);
                $itemNameRus = is_array($itemRow) && isset($itemRow['name_rus']) && is_string($itemRow['name_rus'])
                    ? $itemRow['name_rus']
                    : $itemName;
                $msg .= "— *{$itemNameRus}*: {$qty} шт.\n";
            }
            $msg .= "\n";
        }

        if (empty($foundItems['resources']) && empty($foundItems['crafted_items'])) {
            $msg .= "_Внутри ничего ценного не нашлось..._\n\n";
        }

        $msg .= "💪 Стратегическая находка прокачала твои характеристики!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
            ],
        ];

        $photoPath = 'uploads/telegram/objects/' . strtolower($object['name_en'] ?? 'strategic') . '.jpg';

        $this->safeSendPhoto(
            $tg['telegram_id'],
            base_url($photoPath),
            $msg,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, mixed> $character
     * @param array<string, int|string> $requiredTools
     */
    private function sendInsufficientToolsMessage(array $object, array $character, array $requiredTools): void
    {
        $tg = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tg || empty($tg['telegram_id'])) {
            log_message('error', "StrategicLootHandler: no telegram_id for character_id={$character['id']} (insufficient tools).");
            return;
        }

        $name = $object['name'] ?? 'Стратегический объект';

        $msg  = "🏛 Ты обнаружил *{$name}*, но без специальных инструментов внутрь не пробиться.\n\n";
        $msg .= "🛠 *Тебе нужны:*\n";

        foreach ($requiredTools as $itemName => $quantity) {
            $itemRow = $this->craftedItemsModel->getRowByName($itemName);
            $itemNameRus = is_array($itemRow) && isset($itemRow['name_rus']) && is_string($itemRow['name_rus'])
                ? $itemRow['name_rus']
                : $itemName;
            $inStock = is_array($itemRow) && isset($itemRow['id'])
                ? $this->craftedItemsLogModel
                    ->where('crafted_item_id', (int) $itemRow['id'])
                    ->where('character_id', $character['id'])
                    ->countAllResults()
                : 0;
            $msg .= "— *{$itemNameRus}*: {$quantity} шт. _(в наличии: {$inStock})_\n";
        }

        $msg .= "\n🛒 Скрафти или купи нужные инструменты — и приходи снова.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',   'callback_data' => 'shop'],
                ],
            ],
        ];

        $photoPath = 'uploads/telegram/objects/' . strtolower($object['name_en'] ?? 'strategic') . '.jpg';

        $this->safeSendPhoto(
            $tg['telegram_id'],
            base_url($photoPath),
            $msg,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
