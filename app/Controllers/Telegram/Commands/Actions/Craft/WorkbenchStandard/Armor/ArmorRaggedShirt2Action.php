<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\OutfitModel;   // <-- модель для таблицы outfits
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Шаг 1: Проверяем ресурсы, вычисляем макс. крафт рубахи (RaggedShirt),
 * выводим кнопки [1,5,10,25,50,100] для крафта, если хватает.
 */
class ArmorRaggedShirt2Action extends BaseAction
{
    protected $characterResourceModel;
    protected $characterModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $claimedCellModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $outfitModel;

    /**
     * Массив доступных вариантов крафта (количеств).
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterModel         = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->outfitModel           = new OutfitModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или у него нет персонажа.',
            ]);
        }

        // Проверка: нет ли активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверяем, есть ли у игрока своя база
        $base = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->first();

        if (!$base) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // Проверяем наличие верстака 1 уровня (WorkbenchOne) (через buildings или crafted_items — зависит от логики)
        // Ниже — как в оригинальном примере ArmorRaggedShirt2Action
        $wbench = $this->buildingModel->where('name_en', 'WorkbenchOne')->first();
        if ($wbench) {
            $charHasWb = $this->characterBuildingModel
                ->where('character_id', $character['id'])
                ->where('building_id', $wbench['id'])
                ->first();
            if (!$charHasWb) {
                return $this->sendInsufficientResponse($chatId, 'У вас нет Верстака 1-го уровня.');
            }
        }

        // Подтягиваем описание «RaggedShirt» из outfits
        $outfit = $this->outfitModel->getByEnglishName('RaggedShirt');
        if (!$outfit) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдено описание для RaggedShirt в таблице outfits.",
            ]);
        }

        // Требования для 1 шт.
        $requiredGold = 300;
        $requiredComponents = [
            'Ткань' => 6,
        ];

        // Узнаём, сколько у игрока золота
        $goldAmount = (int) $character['gold'];

        // Узнаём, сколько у игрока крафтовых компонентов
        // (считаем, что "Ткань" лежит в crafted_items_log)
        $insufficient = [];
        $haveResources = [];
        foreach ($requiredComponents as $itemName => $reqQty) {
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                $insufficient[] = "❓ {$itemName} (нет в БД crafted_items)";
                continue;
            }

            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? $logRow['quantity'] : 0;

            $haveResources[$itemName] = $haveQty;
        }

        // Формируем блок описания рубахи
        // Формируем блок описания рубахи
        $rarity     = $outfit['rarity']              ?? 'Common';
        $armorVal   = $outfit['armor_value']         ?? 0;
        $physRes    = $outfit['physical_resistance'] ?? 0;
        $fireRes    = $outfit['fire_resistance']     ?? 0;
        $poisRes    = $outfit['poison_resistance']   ?? 0;
        $speedMod   = $outfit['speed_modifier']      ?? 0;
        $stealthMod = $outfit['stealth_modifier']    ?? 0;
        $weight     = $outfit['weight']             ?? 0;
        $dura       = $outfit['durability']         ?? 0;
        $duraMax    = $outfit['durability_max']     ?? 100;
        $reqStr     = $outfit['required_strength']   ?? 0;
        $reqLvl     = $outfit['required_level']      ?? 1;
        $price      = $outfit['price']              ?? 0;

        $text = "👕 *{$outfit['name']}*\n"
            . "_{$outfit['description']}_\n"
            . "\n*Редкость:* `{$rarity}`\n"
            . "*Защита:* `{$armorVal}`\n"
            . "*Сопротивление физ.:* `{$physRes}%`\n"
            . "*Сопротивление огню:* `{$fireRes}%`\n"
            . "*Сопротивление яду:* `{$poisRes}%`\n"
            . "*Скорость (модификатор):* `{$speedMod}`\n"
            . "*Скрытность:* `{$stealthMod}`\n"
            . "*Прочность:* `{$dura}/{$duraMax}`\n"
            . "*Вес:* `{$weight}`\n"
            . "*Треб. сила:* `{$reqStr}`\n"
            . "*Треб. уровень:* `{$reqLvl}`\n"
            . "*Цена:* `{$price}`\n";

        // Считаем максимальное кол-во, которое можно скрафтить
        $maxCraftable = PHP_INT_MAX;

        // 1) Лимит по золоту
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        if ($maxByGold < $maxCraftable) {
            $maxCraftable = $maxByGold;
        }

        // 2) Лимит по каждому крафтовому компоненту
        foreach ($requiredComponents as $itemName => $reqQty) {
            if (!isset($haveResources[$itemName])) {
                $maxCraftable = 0;
                break;
            }
            $haveQty = $haveResources[$itemName];
            $maxByThisItem = (int) floor($haveQty / $reqQty);
            if ($maxByThisItem < $maxCraftable) {
                $maxCraftable = $maxByThisItem;
            }
        }

        // Если вообще нельзя (даже 1 шт.)
        if ($maxCraftable < 1) {
            $text .= "\nУ вас недостаточно ресурсов для крафта даже 1 шт.\n";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'armorCraft2'],
                    ],
                ]
            ];
        } else {
            // Можно скрафтить хотя бы 1 шт.
            $text .= "\n*Для крафта 1шт:* \n";
            foreach ($requiredComponents as $nm => $qt) {
                $text .= " - {$nm} x {$qt}\n";
            }
            $text .= " - Золото x {$requiredGold}\n\n";
            $text .= "Время крафта (за 1 шт.) ~5 минут\n";

            // Генерируем кнопки «Крафт X шт», но только для тех X, которые <= $maxCraftable
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q}шт",
                        // ! Важно: добавляем "_$q" в callback_data
                        'callback_data' => "startCraftRaggedShirt2_{$q}",
                    ];
                }
            }

            // Разбиваем по 3 кнопки в строке (пример)
            $rows = array_chunk($quantityButtons, 3);

            // Добавим ряд с «Инвентарь / Продать / Купить»
            $rows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
            ];

            $rows[] = [['text' => '⬅️ Назад', 'callback_data' => 'armorCraft2']];

            $keyboard = ['inline_keyboard' => $rows];
        }

        // Убираем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Отправляем фотку (или sendMessage)
        $imagePath = base_url('uploads/telegram/craft/standard/ragged_shirt.jpg');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function sendInsufficientResponse($chatId, $msg)
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ]
            ]
        ];

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $msg,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
