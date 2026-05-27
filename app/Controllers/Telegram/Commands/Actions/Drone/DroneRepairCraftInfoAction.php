<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Tasks\ActiveTasksService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W4 (ADR-063) — preview-экран крафта DroneRepair. Зеркало
 * DroneCargoCraftInfoAction. Чек-лист ресурсов/компонентов/золота/гейта
 * Мастерской робототехники L3 с ✅/❌. Media-off safe (caption самодостаточен).
 */
final class DroneRepairCraftInfoAction extends BaseAction
{
    private const RECIPE_KEY = 'DroneRepair';

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден. Попробуйте /start.',
            ]);
        }

        if ((new ActiveTasksService())->checkRelocationAndBlock(
            (int) $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $recipe = config(\Config\CraftRecipes::class)->get(self::RECIPE_KEY);
        if (! is_array($recipe)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Рецепт дрон-ремонтника не найден.']);
        }

        $characterId   = (int) $character['id'];
        $resourceModel = new CharacterResourceModel();
        $itemsModel    = new CraftedItemsModel();
        $itemsLogModel = new CraftedItemsLogModel();

        $icon = is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '🚁';
        $name = is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : 'Дрон-ремонтник';

        $text = "*{$icon} {$name}*\n\n";
        $text .= "Полевой дрон с паяльной станцией и запчастями. Один взлёт чинит *всех* твоих роботов разом — только за чистое золото, никаких ресурсов. Заряжается на базе (~4 часа до полного заряда). V19 ручной ремонт не отменяется — это премиум-альтернатива для эндгейма.\n\n";
        $text .= "*Для крафта потребуется:*\n\n";

        $hasAll = true;

        $resources = is_array($recipe['resources'] ?? null) ? $recipe['resources'] : [];
        foreach ($resources as $resName => $need) {
            $needInt = is_numeric($need) ? (int) $need : 0;
            $row     = $resourceModel->getResourceByNameAndCharacterId((string) $resName, $characterId);
            $have    = is_array($row) && isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : 0;
            $mark    = $have >= $needInt ? '✅' : '❌';
            if ($have < $needInt) {
                $hasAll = false;
            }
            $emoji = ResourceIconHelper::for((string) $resName);
            $text .= "{$mark} {$emoji} {$resName} — {$have} / {$needInt}\n";
        }

        $components = is_array($recipe['crafted_items'] ?? null) ? $recipe['crafted_items'] : [];
        foreach ($components as $compEn => $need) {
            $needInt = is_numeric($need) ? (int) $need : 0;
            $item    = $itemsModel->getRowByName((string) $compEn);
            $compRus = is_array($item) && isset($item['name_rus']) && is_string($item['name_rus']) ? $item['name_rus'] : (string) $compEn;
            $have    = 0;
            $itemId  = is_array($item) && isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
            if ($itemId > 0) {
                $log  = $itemsLogModel->getItemByCraftedItemIdAndCharacterId($itemId, $characterId);
                $have = is_array($log) && isset($log['quantity']) && is_numeric($log['quantity']) ? (int) $log['quantity'] : 0;
            }
            $mark = $have >= $needInt ? '✅' : '❌';
            if ($have < $needInt) {
                $hasAll = false;
            }
            $emoji = ResourceIconHelper::for($compRus);
            $text .= "{$mark} {$emoji} {$compRus} — {$have} / {$needInt}\n";
        }

        $goldNeed = is_numeric($recipe['gold_required'] ?? null) ? (int) $recipe['gold_required'] : 0;
        $goldHave = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        $goldMark = $goldHave >= $goldNeed ? '✅' : '❌';
        if ($goldHave < $goldNeed) {
            $hasAll = false;
        }
        $text .= "{$goldMark} 💰 Золото — {$goldHave} / {$goldNeed}\n";

        $needLevel = $this->requiredWorkshopLevel($recipe);
        $haveLevel = $this->workshopLevel($characterId);
        $gateMark  = $haveLevel >= $needLevel ? '✅' : '❌';
        if ($haveLevel < $needLevel) {
            $hasAll = false;
        }
        $text .= "{$gateMark} 🏭 Мастерская робототехники — ур. {$haveLevel} / {$needLevel}\n";

        if ($hasAll) {
            $text .= "\nВсё готово — можно крафтить! 🛠️\n";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '🛠️ Крафтить', 'callback_data' => 'genericCraft_DroneRepair_1'],
                ['text' => '◀️ Назад',    'callback_data' => 'standardCraft'],
            ]]];
        } else {
            $text .= "\n_Не хватает части требований — подкопи / прокачай Мастерскую и возвращайся._\n";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '◀️ Назад',    'callback_data' => 'standardCraft'],
            ]]];
        }

        // W4: art-tail ждёт `php spark images:generate --missing` — до тех пор fallback
        // на image_in_progress (standard_craft_area.jpg), который уже существует.
        // Проверка через FCPATH (local disk), а не HTTP — encodeFile() fopen'ит URL.
        $imageRel = is_string($recipe['image_completed'] ?? null) ? $recipe['image_completed'] : '';
        $imageAbs = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $imageRel);
        if ($imageRel === '' || ! is_file($imageAbs)) {
            $fallback = is_string($recipe['image_in_progress'] ?? null) ? $recipe['image_in_progress'] : '';
            $imageRel = $fallback;
        }
        $imagePath = base_url($imageRel);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * @param array<string,mixed> $recipe
     */
    private function requiredWorkshopLevel(array $recipe): int
    {
        $levels = is_array($recipe['required_building_levels'] ?? null) ? $recipe['required_building_levels'] : [];
        $raw    = $levels['RoboticsWorkshop'] ?? 3;
        return is_numeric($raw) ? max(1, (int) $raw) : 3;
    }

    private function workshopLevel(int $characterId): int
    {
        $building   = (new BuildingModel())->where('name_en', 'RoboticsWorkshop')->first();
        $buildingId = is_array($building) && isset($building['id']) && is_numeric($building['id']) ? (int) $building['id'] : 0;
        if ($buildingId === 0) {
            return 0;
        }
        $owned = (new CharacterBuildingModel())
            ->where('character_id', $characterId)
            ->where('building_id', $buildingId)
            ->first();
        return is_array($owned) && isset($owned['level']) && is_numeric($owned['level']) ? (int) $owned['level'] : 0;
    }
}
