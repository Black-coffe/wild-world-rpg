<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V18 (ADR-049) — общий preview для T2-роботов (Разведчик/Промышленник).
 *
 * Требования читаются из Config\CraftRecipes (single source of truth) — не
 * хардкод как в legacy RobotExplorer2Action. Показывает have/need по ресурсам,
 * компонентам, золоту + статус гейта RoboticsWorkshop L2. Кнопка крафта —
 * только когда всё доступно И гейт пройден. Caption самодостаточен (media-off).
 */
abstract class RobotT2PreviewAction extends BaseAction
{
    /** Ключ рецепта в Config\CraftRecipes (RobotScout / RobotIndustrial). */
    abstract protected function recipeKey(): string;

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $recipe = config(\Config\CraftRecipes::class)->get($this->recipeKey());
        if (!is_array($recipe)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Рецепт робота не найден.']);
        }

        $characterId = (int) $character['id'];
        $resourceModel  = new CharacterResourceModel();
        $itemsModel     = new CraftedItemsModel();
        $itemsLogModel  = new CraftedItemsLogModel();

        $icon = is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '🤖';
        $name = is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : 'Робот';

        $text = "*{$icon} {$name}*\n\n";
        $descMap = [
            'RobotScout'      => 'Продвинутый робот-разведчик: за запуск открывает значительно больше клеток карты, чем обычный Исследователь.',
            'RobotIndustrial' => 'Промышленный робот-добытчик: приносит больше ресурсов и обходит больше клеток вокруг базы, чем обычный Добытчик.',
        ];
        $text .= ($descMap[$this->recipeKey()] ?? '') . "\n\n*Для крафта потребуется:*\n\n";

        $hasAll = true;

        // Ресурсы (ключи — русские названия).
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

        // Компоненты (ключи — английские name_eng → показываем name_rus).
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

        // Золото.
        $goldNeed = is_numeric($recipe['gold_required'] ?? null) ? (int) $recipe['gold_required'] : 0;
        $goldHave = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        $goldMark = $goldHave >= $goldNeed ? '✅' : '❌';
        if ($goldHave < $goldNeed) {
            $hasAll = false;
        }
        $text .= "{$goldMark} 💰 Золото — {$goldHave} / {$goldNeed}\n";

        // Гейт уровня Мастерской робототехники (required_building_levels).
        $needLevel  = $this->requiredWorkshopLevel($recipe);
        $haveLevel  = $this->workshopLevel($characterId);
        $gateMark   = $haveLevel >= $needLevel ? '✅' : '❌';
        $gateMet    = $haveLevel >= $needLevel;
        if (!$gateMet) {
            $hasAll = false;
        }
        $text .= "{$gateMark} 🏭 Мастерская робототехники — ур. {$haveLevel} / {$needLevel}\n";

        if ($hasAll) {
            $text .= "\nВсё готово — можно крафтить! 🛠️\n";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '🛠️ Крафтить', 'callback_data' => 'genericCraft_' . $this->recipeKey() . '_1'],
                ['text' => '◀️ Назад',    'callback_data' => 'robotsCraft2'],
            ]]];
        } else {
            $text .= "\n__Не хватает части требований — подкопи / прокачай Мастерскую и возвращайся.__\n";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '◀️ Назад',    'callback_data' => 'robotsCraft2'],
            ]]];
        }

        // Резолв как у сиблингов (DroneRepair/DroneCombat): FCPATH-проверка на диске, иначе
        // fallback на image_in_progress. encodeFile() fopen'ит путь — пустой/несуществующий
        // image_completed уронил бы экран осмотра (класс-баг Gear, урок art-but-invisible).
        $imageRel = is_string($recipe['image_completed'] ?? null) ? $recipe['image_completed'] : '';
        $imageAbs = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $imageRel);
        if ($imageRel === '' || ! is_file($imageAbs)) {
            $imageRel = is_string($recipe['image_in_progress'] ?? null) ? $recipe['image_in_progress'] : '';
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
     * Требуемый уровень RoboticsWorkshop из recipe.required_building_levels (default 1).
     *
     * @param array<string,mixed> $recipe
     */
    private function requiredWorkshopLevel(array $recipe): int
    {
        $levels = is_array($recipe['required_building_levels'] ?? null) ? $recipe['required_building_levels'] : [];
        $raw    = $levels['RoboticsWorkshop'] ?? 1;
        return is_numeric($raw) ? max(1, (int) $raw) : 1;
    }

    /** Текущий уровень RoboticsWorkshop у персонажа (0, если нет здания). */
    private function workshopLevel(int $characterId): int
    {
        $building = (new BuildingModel())->where('name_en', 'RoboticsWorkshop')->first();
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
