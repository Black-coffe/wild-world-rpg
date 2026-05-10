<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\WeaponModel;
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
 * Класс для вывода информации по крафту "Усовершенствованная бита" (WiredBat).
 * Показывает требования (золото, крафтовые предметы, сырьевые ресурсы, силу/уровень),
 * генерирует Inline-кнопки (сколько шт. скрафтить).
 */
class WeaponWiredBat2Action extends BaseAction
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

    private array $craftQuantities = [1, 5, 10, 25, 50]; // варианты кнопок "Крафт X шт."

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

        // Получаем данные пользователя/персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или у него нет персонажа.',
            ]);
        }

        // Проверка: не идёт ли переезд (если да — блокируем крафт)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверяем наличие базы
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // Проверяем наличие Верстака 1-го уровня, если требуется
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

        // Ищем «WiredBat» в таблице weapons
        $weapon = $this->weaponModel->getByEnglishName('EnhancedBat');
        if (!$weapon) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдена запись с name_en='WiredBat' в таблице weapons.",
            ]);
        }

        // ---- Требования к крафту (пример) ----
        $requiredGold = 250; // Золото за 1 шт.
        $requiredRawResources = [
            ['name' => 'Кости животных', 'qty' => 2],
            ['name' => 'Кожа животных', 'qty' => 2],
            ['name' => 'Смола деревьев', 'qty' => 2],
            ['name' => 'Базальт', 'qty' => 1],
            ['name' => 'Древесина', 'qty' => 16],
        ];
        $requiredCraftedItems = [
            'Ткань'  => 3,
            'Металл фрагменты' => 3,
            'Монтировка' => 1,
        ];

        // Требования по характеристикам (берём из weapon или задаём вручную)
        $strengthRequired = max(5, (int)$weapon['required_strength']); // Условно пусть нужна сила >=5
        $levelRequired    = max(2, (int)$weapon['required_level']);

        // Собираем список «нехваток»
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
            // смотрим, сколько есть у игрока
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

        // --- Достаем характеристики оружия из базы (for display)
        $nameSafe  = $this->escapeMarkdown($weapon['name'] ?? '???');
        $descRaw   = $weapon['description'] ?? '';
        $descSafe  = $this->escapeMarkdown($descRaw);

        $rarity    = $weapon['rarity'] ?? 'Common';
        $damage    = $weapon['damage_value'] ?? 0;
        $dType     = $weapon['damage_type']  ?? 'Physical';
        $rangeVal  = $weapon['range_value']  ?? 1;
        $atkSpeed  = $weapon['attack_speed'] ?? 1;
        $weight    = $weapon['weight']       ?? 2;
        $durabMax  = $weapon['durability_max'] ?? 25;
        $reqStr    = (int)$weapon['required_strength'];
        $reqLvl    = (int)$weapon['required_level'];
        $weaponPrice= (int)$weapon['price'];

        // Формируем основной текст
        $text  = "🏏 *{$nameSafe}*\n";
        $text .= "_{$descSafe}_\n\n";
        $text .= "*Редкость:* `{$rarity}`\n";
        $text .= "*Урон:* `{$damage}` ({$dType})\n";
        $text .= "*Дальность:* `{$rangeVal}`\n";
        $text .= "*Скорость атаки:* `{$atkSpeed}`\n";
        $text .= "*Макс. прочность:* `{$durabMax}`\n";
        $text .= "*Вес:* `{$weight}`\n";
        $text .= "*Треб. сила:* `{$strengthRequired}`\n";
        $text .= "*Треб. уровень:* `{$levelRequired}`\n";
        $text .= "*Цена (продажи):* `{$weaponPrice}`\n";

        // Если есть несоответствия — сообщаем
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
                'callback_query_id' => $this->callbackQuery->getId()
            ]);

            $imagePath = base_url('uploads/telegram/craft/standard/wired_bat.jpg'); // подставьте свою картинку
            return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Иначе считаем, сколько максимум можно скрафтить
        $maxCraftable = PHP_INT_MAX;

        // 1) золото
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        $maxCraftable = min($maxCraftable, $maxByGold);

        // 2) предметы
        foreach ($requiredCraftedItems as $nm => $reqQty) {
            $haveQty  = $haveCrafted[$nm] ?? 0;
            $localMax = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // 3) сырьё
        foreach ($requiredRawResources as $ri) {
            $rName   = $ri['name'];
            $reqQty  = $ri['qty'];
            $haveQty = $haveRawResources[$rName] ?? 0;
            $localMax= (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Добавляем инфо о расходе
        $text .= "\n*Для крафта 1 шт.* нужно:\n";
        foreach ($requiredCraftedItems as $nm => $qt) {
            $text .= " - " . $this->escapeMarkdown($nm) . " x {$qt}\n";
        }
        foreach ($requiredRawResources as $ri) {
            $rsafe = $this->escapeMarkdown($ri['name']);
            $text .= " - {$rsafe} x {$ri['qty']}\n";
        }
        $text .= " - Золото x {$requiredGold}\n\n";
        $text .= "Примерное время крафта (за 1 шт.): ~6 минут\n";

        // Формируем кнопки
        if ($maxCraftable < 1) {
            // недостаточно ресурсов даже на 1
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
            // генерируем кнопки «Крафт X шт.»
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q}шт",
                        'callback_data' => "genericCraft_WiredBat_{$q}",
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

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $imagePath = base_url('uploads/telegram/craft/standard/wired_bat.jpg');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Если нет базы/верстака.
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
