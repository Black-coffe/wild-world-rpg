<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\WeaponModel;             // <- для таблицы weapons
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс для отображения крафта "Металлическое копьё" (MetalSpear).
 * Показывает требования (золото, крафтовые предметы, сырьевые ресурсы, силу и уровень),
 * рассчитывает, сколько штук можно скрафтить, генерирует inline-кнопки.
 */
class WeaponMetalSpear2Action extends BaseAction
{
    protected $weaponModel;
    protected $characterModel;
    protected $claimModel;
    protected $buildingModel;
    protected $charBuildingModel;
    protected $resourceModel;
    protected $charResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество вариантов крафта (1, 5, 10…).
     */
    private array $craftQuantities = [1, 5, 10, 25, 50];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->weaponModel         = new WeaponModel();
        $this->characterModel      = new CharacterModel();
        $this->claimModel          = new ClaimedCellModel();
        $this->buildingModel       = new BuildingModel();
        $this->charBuildingModel   = new CharacterBuildingModel();
        $this->resourceModel       = new ResourceModel();
        $this->charResourceModel   = new CharacterResourceModel();
        $this->craftedItemsModel   = new CraftedItemsModel();
        $this->craftedItemsLogModel= new CraftedItemsLogModel();
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

        // Проверяем наличие базы (лагеря)
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // Проверяем наличие Верстака (WorkbenchOne) — если по лору он необходим
        $wbench = $this->buildingModel->where('name_en', 'WorkbenchOne')->first();
        if ($wbench) {
            $charHasWb = $this->charBuildingModel
                ->where('character_id', $character['id'])
                ->where('building_id', $wbench['id'])
                ->first();
            if (!$charHasWb) {
                return $this->sendInsufficientResponse($chatId, 'У вас нет Верстака 1-го уровня.');
            }
        }

        // Ищем «MetalSpear» в таблице weapons
        $weapon = $this->weaponModel->getByEnglishName('MetalSpear');
        if (!$weapon) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдена запись с name_en='MetalSpear' в таблице weapons.",
            ]);
        }

        // --- Устанавливаем свои требования к крафту ---
        // (Если хотите, часть можно брать из полей weapon)
        $requiredGold = 200;
        // Сырьевые ресурсы (придуманные, по вашему балансу)
        $requiredRawResources = [
            ['name' => 'Древесина',         'qty' => 3],
            ['name' => 'Редкие металлы',    'qty' => 2],
        ];
        // Крафтовые предметы (например, 'Кузнечный молот' или 'Заготовка лезвия')
        $requiredCraftedItems = [
            'Монтировка'        => 1,
            'Ткань'             => 2,
            'Металл фрагменты'  => 2,
        ];

        // Уровень / Сила (берём либо из weapon, либо «умолчание»)
        $strengthRequired = max(2, (int)$weapon['required_strength']);
        $levelRequired    = max(1, (int)$weapon['required_level']);

        // Начинаем собирать «недостатки»
        $insufficientDetails = [];

        // Проверяем силу
        if ($character['strength'] < $strengthRequired) {
            $insufficientDetails[] = "Требуется сила {$strengthRequired}, у вас {$character['strength']}";
        }

        // Проверяем уровень
        if ($character['level'] < $levelRequired) {
            $insufficientDetails[] = "Требуется уровень {$levelRequired}, у вас {$character['level']}";
        }

        // Проверяем золото
        $goldAmount = (int) $character['gold'];
        if ($goldAmount < $requiredGold) {
            $insufficientDetails[] = "Золото: нужно {$requiredGold}, у вас {$goldAmount}";
        }

        // Проверяем крафтовые предметы
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

        // Проверяем сырьевые ресурсы
        $haveRawResources = [];
        foreach ($requiredRawResources as $resInfo) {
            $rName = $resInfo['name'];
            $rQty  = (int)$resInfo['qty'];
            $resRow= $this->resourceModel->getResourceByName($rName);
            if (!$resRow) {
                $insufficientDetails[] = "❓ {$rName} (нет в БД resources)";
                continue;
            }
            $charRes = $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resRow['id'])
                ->first();
            $haveQty = $charRes ? (int)$charRes['quantity'] : 0;
            if ($haveQty < $rQty) {
                $insufficientDetails[] = "{$rName}: нужно {$rQty}, у вас {$haveQty}";
            }
            $haveRawResources[$rName] = $haveQty;
        }

        // --- Информация о самом копье (из weapons) ---
        // damage_value, damage_type, range_value, required_strength, rarity, etc.
        $nameSafe   = $this->escapeMarkdown($weapon['name'] ?? '???');
        $descRaw    = $weapon['description'] ?? '';
        $descSafe   = $this->escapeMarkdown($descRaw);

        $rarity   = $weapon['rarity'] ?? 'Common';
        $damage   = $weapon['damage_value'] ?? 0;
        $dType    = $weapon['damage_type']  ?? 'Physical';
        $rangeVal = $weapon['range_value']  ?? 1;
        $atkSpeed = $weapon['attack_speed'] ?? 1;
        $weigh    = $weapon['weight']       ?? 0;
        $durabMax = $weapon['durability_max'] ?? 100;
        // В самом weapon есть required_strength, required_level
        $reqStr = (int)$weapon['required_strength'];
        $reqLvl= (int)$weapon['required_level'];
        // Если хотим объединить:
        $reqStr = max($reqStr, $strengthRequired);
        $reqLvl = max($reqLvl, $levelRequired);

        $weaponPrice = $weapon['price'] ?? 0;

        // Формируем текст
        $text  = "🗡️ *{$nameSafe}*\n";
        $text .= "_{$descSafe}_\n\n";
        $text .= "*Редкость:* `{$rarity}`\n";
        $text .= "*Урон:* `{$damage}` ({$dType})\n";
        $text .= "*Дальность:* `{$rangeVal}`\n";
        $text .= "*Скорость атаки:* `{$atkSpeed}`\n";
        $text .= "*Макс. прочность:* `{$durabMax}`\n";
        $text .= "*Вес:* `{$weigh}`\n";
        $text .= "*Треб. сила:* `{$reqStr}`\n";
        $text .= "*Треб. уровень:* `{$reqLvl}`\n";
        $text .= "*Цена (продажи):* `{$weaponPrice}`\n";

        // Если есть несоответствия, сразу выдаём сообщение
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

            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
            ]);

            $imagePath = base_url('uploads/telegram/craft/standard/metal_spear.jpg');
            return Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Иначе считаем, сколько максимум шт. можно скрафтить
        $maxCraftable = PHP_INT_MAX;

        // 1) Золото
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        $maxCraftable = min($maxCraftable, $maxByGold);

        // 2) Крафтовые предметы
        foreach ($requiredCraftedItems as $nm => $reqQty) {
            $haveQty   = $haveCrafted[$nm] ?? 0;
            $localMax  = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // 3) Сырьевые ресурсы
        foreach ($requiredRawResources as $resInfo) {
            $rName   = $resInfo['name'];
            $reqQty  = $resInfo['qty'];
            $haveQty = $haveRawResources[$rName] ?? 0;
            $localMax= (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Добавляем информацию о требованиях для 1 шт.
        $text .= "\n*Для крафта 1 шт.* нужно:\n";
        foreach ($requiredCraftedItems as $nm => $qt) {
            $text .= " - " . $this->escapeMarkdown($nm) . " x {$qt}\n";
        }
        foreach ($requiredRawResources as $ri) {
            $nSafe = $this->escapeMarkdown($ri['name']);
            $text .= " - {$nSafe} x {$ri['qty']}\n";
        }
        $text .= " - Золото x {$requiredGold}\n\n";
        $text .= "Примерное время крафта (за 1 шт.): ~5-10 минут\n";

        // Формируем inline-клавиатуру
        if ($maxCraftable < 1) {
            // даже при удовлетворении силы/уровня не хватает ресурсов
            $text .= "\nРесурсов/предметов всё равно не хватает даже на 1 шт.\n";
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
            // генерируем кнопки с кол-вом
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q}шт",
                        // Можно назвать `startCraftMetalSpear_{$q}`
                        'callback_data' => "genericCraft_MetalSpear_{$q}",
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

        // Убираем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Отправка
        $imagePath = base_url('uploads/telegram/craft/standard/metal_spear.jpg');
        return Request::sendPhoto([
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
