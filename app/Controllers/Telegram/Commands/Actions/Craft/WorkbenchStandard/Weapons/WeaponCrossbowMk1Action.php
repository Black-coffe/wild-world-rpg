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
 * Показывает информацию о крафте «Арбалет Mk.I» (name_en='CrossbowMk1').
 * Проверяет, хватает ли игроку ресурсов/характеристик, и формирует кнопки "Крафт X шт."
 */
class WeaponCrossbowMk1Action extends BaseAction
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

    private array $craftQuantities = [1, 5, 10, 25, 50]; // варианты "Крафт X шт."

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

        // 1) Получаем данные пользователя/персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или у него нет персонажа.',
            ]);
        }

        // 2) Проверка: не идёт ли переезд базы
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // 3) Проверяем наличие базы
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // 4) Проверяем, есть ли Верстак (уровень 1), если это требуется
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

        // 5) Ищем «CrossbowMk1» в таблице weapons
        $weapon = $this->weaponModel->getByEnglishName('CrossbowMk1');
        if (!$weapon) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдена запись с name_en='CrossbowMk1' в таблице weapons.",
            ]);
        }

        // 6) Задаём требования к крафту (примерные)
        $requiredGold = 180; // золото за 1 шт.
        $requiredRawResources = [
            ['name' => 'Шкура животных',    'qty' => 2],
            ['name' => 'Водные растения',     'qty' => 5],
            ['name' => 'Смола деревьев','qty' => 5],
            ['name' => 'Шерсть животных','qty' => 7],
            ['name' => 'Лианы','qty' => 3],
            ['name' => 'Древесина','qty' => 3],
        ];
        $requiredCraftedItems = [
            'Металл фрагменты' => 1,
            'Ткань'              => 4,
        ];

        // 7) Проверяем характеристики (сила/ловкость/уровень).
        //    Из weapons можно взять required_strength, required_level, required_agility.
        //    Допустим, для арбалета нужно хотя бы 10 ловкости, 3 силы.
        $strengthRequired = max(3, (int)$weapon['required_strength']);
        $levelRequired    = max(2, (int)$weapon['required_level']);
        // Если хотим жёстко прописать ловкость:
        $agilityRequired  = max(10, (int)$weapon['required_agility']);

        // Собираем «недостатки»
        $insufficientDetails = [];

        if ($character['strength'] < $strengthRequired) {
            $insufficientDetails[] = "Требуется сила {$strengthRequired}, у вас {$character['strength']}";
        }
        if ($character['agility'] < $agilityRequired) {
            $insufficientDetails[] = "Требуется ловкость {$agilityRequired}, у вас {$character['agility']}";
        }
        if ($character['level'] < $levelRequired) {
            $insufficientDetails[] = "Требуется уровень {$levelRequired}, у вас {$character['level']}";
        }

        // Проверяем золото
        $goldAmount = (int)$character['gold'];
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

        // 8) Формируем описание оружия
        $nameSafe = $this->escapeMarkdown($weapon['name'] ?? 'Арбалет Mk.I');
        $descSafe = $this->escapeMarkdown($weapon['description'] ?? '');

        $rarity    = $weapon['rarity']        ?? 'Uncommon';
        $damage    = $weapon['damage_value']  ?? 8;
        $dType     = $weapon['damage_type']   ?? 'Piercing';
        $rangeVal  = $weapon['range_value']   ?? 3;
        $atkSpeed  = $weapon['attack_speed']  ?? 0.5;
        $durabMax  = $weapon['durability_max']?? 30;
        $weight    = $weapon['weight']        ?? 4;
        $weaponPrice= (int)($weapon['price']  ?? 120);

        $text  = "🏹 *{$nameSafe}*\n";
        $text .= "_{$descSafe}_\n\n";
        $text .= "*Редкость:* `{$rarity}`\n";
        $text .= "*Урон:* `{$damage}` ({$dType})\n";
        $text .= "*Дальность:* `{$rangeVal}`\n";
        $text .= "*Скорость атаки:* `{$atkSpeed}`\n";
        $text .= "*Макс. прочность:* `{$durabMax}`\n";
        $text .= "*Вес:* `{$weight}`\n";
        $text .= "*Треб. сила:* `{$strengthRequired}`\n";
        $text .= "*Треб. ловкость:* `{$agilityRequired}`\n";
        $text .= "*Треб. уровень:* `{$levelRequired}`\n";
        $text .= "*Цена (продажи):* `{$weaponPrice}`\n";

        // Проверяем, есть ли «недостатки»
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

            $imagePath = base_url('uploads/telegram/craft/standard/crossbow_mk1.jpg'); // вашу картинку
            return Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 9) Если всего хватает, считаем, сколько максимум можно скрафтить
        $maxCraftable = PHP_INT_MAX;

        // золото
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        $maxCraftable = min($maxCraftable, $maxByGold);

        // крафтовые предметы
        foreach ($requiredCraftedItems as $nm => $reqQty) {
            $haveQty   = $haveCrafted[$nm] ?? 0;
            $localMax  = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // сырьевые ресурсы
        foreach ($requiredRawResources as $ri) {
            $rName   = $ri['name'];
            $reqQty  = $ri['qty'];
            $haveQty = $haveRawResources[$rName] ?? 0;
            $localMax= (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // 10) Формируем текст о расходе
        $text .= "\n*Для крафта 1 шт.* нужно:\n";
        foreach ($requiredCraftedItems as $nm => $qt) {
            $text .= " - {$nm} x {$qt}\n";
        }
        foreach ($requiredRawResources as $ri) {
            $text .= " - {$ri['name']} x {$ri['qty']}\n";
        }
        $text .= " - Золото x {$requiredGold}\n\n";
        $text .= "Примерное время крафта (за 1 шт.): ~12 минут\n";

        // 11) Кнопки
        if ($maxCraftable < 1) {
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
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q}шт",
                        'callback_data' => "genericCraft_CrossbowMk1_{$q}",
                    ];
                }
            }
            $rows = array_chunk($quantityButtons, 3);
            $rows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍 Купить',    'callback_data' => 'buy'],
            ];
            $keyboard = [ 'inline_keyboard' => $rows ];
        }

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $imagePath = base_url('uploads/telegram/craft/standard/crossbow_mk1.jpg');
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Сообщение, если нет базы/верстака.
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
     * Экранируем спецсимволы в Markdown.
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
