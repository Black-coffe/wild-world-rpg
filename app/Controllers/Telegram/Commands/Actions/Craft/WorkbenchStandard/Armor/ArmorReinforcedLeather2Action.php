<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\OutfitModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс для отображения крафта "Усиленная кожаная куртка" (ReinforcedLeatherJacket).
 * Показывает требования (золото, крафтовые предметы, сырьевые ресурсы, силу и уровень персонажа),
 * рассчитывает, сколько штук можно скрафтить, и генерирует inline-кнопки с вариантами.
 */
class ArmorReinforcedLeather2Action extends BaseAction
{
    protected $characterModel;
    protected $claimModel;
    protected $buildingModel;
    protected $charBuildingModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $outfitModel;
    protected $resourceModel;
    protected $charResourceModel;

    /**
     * Возможные варианты крафта (количество).
     */
    private array $craftQuantities = [1, 5, 10, 25, 50];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterModel       = new CharacterModel();
        $this->claimModel           = new ClaimedCellModel();
        $this->buildingModel        = new BuildingModel();
        $this->charBuildingModel    = new CharacterBuildingModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->outfitModel          = new OutfitModel();

        $this->resourceModel        = new ResourceModel();
        $this->charResourceModel    = new CharacterResourceModel();
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

        // Проверка активного переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверяем наличие базы
        $base = $this->claimModel
            ->where('character_id', $character['id'])
            ->first();
        if (!$base) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // Проверяем наличие Верстака 1-го уровня (пример)
        $workbench = $this->buildingModel->where('name_en', 'WorkbenchOne')->first();
        if ($workbench) {
            $charHasWb = $this->charBuildingModel
                ->where('character_id', $character['id'])
                ->where('building_id', $workbench['id'])
                ->first();
            if (!$charHasWb) {
                return $this->sendInsufficientResponse($chatId, 'У вас нет Верстака 1-го уровня.');
            }
        }

        // Ищем «ReinforcedLeatherJacket» в таблице outfits
        $outfit = $this->outfitModel
            ->where('name_en', 'ReinforcedLeatherJacket')
            ->first();
        if (!$outfit) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдена запись с name_en='ReinforcedLeatherJacket' в таблице outfits.",
            ]);
        }

        // --- Требования к крафту ---
        $requiredGold = 1200; // например, 1200 золотых
        $requiredRawResources = [
            ['name' => 'Кожа животных', 'qty' => 6],
            ['name' => 'Древесина',     'qty' => 4],
            ['name' => 'Редкие металлы',    'qty' => 3],
        ];
        $requiredCraftedItems = [
            'Складной нож'      => 1,
            'Ткань'             => 12,
            'Металл фрагменты'  => 1,
        ];

        // --- Проверяем параметры персонажа (сила >=5, уровень >=3)
        $strengthRequired = 5;
        $levelRequired    = 3;

        // Собираем недостающую информацию
        $insufficientDetails = [];

        // 0) Проверяем силу
        if ($character['strength'] < $strengthRequired) {
            $insufficientDetails[] = "Требуется сила {$strengthRequired}, у вас {$character['strength']}";
        }

        // 0.1) Проверяем уровень
        if ($character['level'] < $levelRequired) {
            $insufficientDetails[] = "Требуется уровень {$levelRequired}, у вас {$character['level']}";
        }

        // 1) Золото
        $goldAmount = (int) $character['gold'];
        if ($goldAmount < $requiredGold) {
            $insufficientDetails[] = "Золото: нужно {$requiredGold}, у вас {$goldAmount}";
        }

        // 2) Крафтовые предметы
        $haveCrafted = [];
        foreach ($requiredCraftedItems as $itemName => $reqQty) {
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                $insufficientDetails[] = "❓ {$itemName} (нет в БД crafted_items)";
                continue;
            }
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int)$logRow['quantity'] : 0;
            if ($haveQty < $reqQty) {
                $insufficientDetails[] = "{$itemName}: нужно {$reqQty}, у вас {$haveQty}";
            }
            $haveCrafted[$itemName] = $haveQty;
        }

        // 3) Сырьевые ресурсы
        $haveRawResources = [];
        foreach ($requiredRawResources as $resInfo) {
            $rName = $resInfo['name'];
            $rQty  = (int)$resInfo['qty'];
            // Ищем ресурс
            $resourceRow = $this->resourceModel->getResourceByName($rName);
            if (!$resourceRow) {
                $insufficientDetails[] = "❓ {$rName} (нет в БД resources)";
                continue;
            }
            $charRes = $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->first();
            $haveQty = $charRes ? (int)$charRes['quantity'] : 0;
            if ($haveQty < $rQty) {
                $insufficientDetails[] = "{$rName}: нужно {$rQty}, у вас {$haveQty}";
            }
            $haveRawResources[$rName] = $haveQty;
        }

        // --- Из outfits ---
        $nameSafe  = $this->escapeMarkdown($outfit['name'] ?? '???');
        $descRaw   = $outfit['description'] ?? '';
        $descSafe  = $this->escapeMarkdown($descRaw);

        $rarity     = $outfit['rarity']              ?? 'Rare';
        $armorVal   = $outfit['armor_value']         ?? 0;
        $physRes    = $outfit['physical_resistance'] ?? 0;
        $fireRes    = $outfit['fire_resistance']     ?? 0;
        $poisRes    = $outfit['poison_resistance']   ?? 0;
        $speedMod   = $outfit['speed_modifier']      ?? 0;
        $stealthMod = $outfit['stealth_modifier']    ?? 0;
        $weight     = $outfit['weight']              ?? 0;
        $dura       = $outfit['durability']          ?? 0;
        $duraMax    = $outfit['durability_max']      ?? 100;
        $reqStr     = $outfit['required_strength']   ?? $strengthRequired;
        $reqLvl     = $outfit['required_level']      ?? $levelRequired;
        $price      = $outfit['price']               ?? 0;

        // Формируем описание
        $text = "🪡 *{$nameSafe}*\n"
            . "_{$descSafe}_\n\n"
            . "*Редкость:* `{$rarity}`\n"
            . "*Защита:* `{$armorVal}`\n"
            . "*Сопротивление физ.:* `{$physRes}%`\n"
            . "*Сопротивление огню:* `{$fireRes}%`\n"
            . "*Сопротивление яду:* `{$poisRes}%`\n"
            . "*Скорость (модиф.):* `{$speedMod}`\n"
            . "*Скрытность:* `{$stealthMod}`\n"
            . "*Прочность:* `{$dura}/{$duraMax}`\n"
            . "*Вес:* `{$weight}`\n"
            . "*Треб. сила:* `{$reqStr}`\n"
            . "*Треб. уровень:* `{$reqLvl}`\n"
            . "*Цена:* `{$price}`\n";

        // Если есть несоответствия (сила, уровень, золото, ресурсы), сразу показываем, что 0 шт.
        if (!empty($insufficientDetails)) {
            $text .= "\nНедостаточно условий для крафта *1 шт.*\n";
            $text .= "\n*Не хватает:*";
            foreach ($insufficientDetails as $line) {
                $text .= "\n- " . $this->escapeMarkdown($line);
            }
            $text .= "\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍 Купить',    'callback_data' => 'buy'],
                    ],
                ],
            ];

            // Убираем "часики" на кнопке
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
            ]);

            // Возвращаем сообщение об ошибке
            $imagePath = base_url('uploads/telegram/craft/standard/reinforced_leather_jacket.jpg');
            return \App\Services\Notifications\MediaSender::sendPhotoOrText([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Если персонажу хватает силы, уровня, золота, предметов и ресурсов…
        // Рассчитываем максимальное кол-во
        $maxCraftable = PHP_INT_MAX;

        // 1) Лимит по золоту
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        $maxCraftable = min($maxCraftable, $maxByGold);

        // 2) Лимит по крафтовым предметам
        foreach ($requiredCraftedItems as $nm => $reqQty) {
            $haveQty   = $haveCrafted[$nm] ?? 0;
            $localMax  = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // 3) Лимит по сырьевым ресурсам
        foreach ($requiredRawResources as $resInfo) {
            $rName   = $resInfo['name'];
            $reqQty  = $resInfo['qty'];
            $haveQty = $haveRawResources[$rName] ?? 0;
            $localMax = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Генерируем блок c информацией о стоимости/ресурсах + кнопки крафта
        $text .= "\n*Для крафта 1 шт.* нужно:\n";
        foreach ($requiredCraftedItems as $nm => $qt) {
            $nSafe = $this->escapeMarkdown($nm);
            $text .= " - {$nSafe} x {$qt}\n";
        }
        foreach ($requiredRawResources as $resInfo) {
            $rName  = $this->escapeMarkdown($resInfo['name']);
            $rQty   = $resInfo['qty'];
            $text .= " - {$rName} x {$rQty}\n";
        }
        $text .= " - Золото x {$requiredGold}\n\n";
        $text .= "Примерное время крафта (за 1 шт.): ~15-20 минут\n";

        // Если maxCraftable < 1, то нет смысла выводить кнопки
        if ($maxCraftable < 1) {
            $text .= "\nДаже при достаточной силе/уровне, тебе не хватает ресурсов для 1 шт.\n";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍 Купить',    'callback_data' => 'buy'],
                    ],
                ],
            ];
        } else {
            // Можно крафтить
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q} шт",
                        'callback_data' => "startCraftReinforcedLeather2_{$q}",
                    ];
                }
            }
            $rows = array_chunk($quantityButtons, 3);

            $rows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍 Купить',    'callback_data' => 'buy'],
            ];

            $keyboard = ['inline_keyboard' => $rows];
        }

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Отправляем финальное сообщение (с фото)
        $imagePath = base_url('uploads/telegram/craft/standard/reinforced_leather_jacket.jpg');
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Сообщение об отсутствии базы или верстака.
     */
    private function sendInsufficientResponse($chatId, $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory']
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

    /**
     * Экранирование спецсимволов Markdown.
     */
    private function escapeMarkdown(string $text): string
    {
        $replacements = [
            '_'  => '\_',
            '*'  => '\*',
            '['  => '\[',
            ']'  => '\]',
            '('  => '\(',
            ')'  => '\)',
            '~'  => '\~',
            '`'  => '\`',
            '>'  => '\>',
            '#'  => '\#',
            '+'  => '\+',
            '-'  => '\-',
            '='  => '\=',
            '|'  => '\|',
            '{'  => '\{',
            '}'  => '\}',
            '.'  => '\.',
            '!'  => '\!',
        ];
        return strtr($text, $replacements);
    }
}
