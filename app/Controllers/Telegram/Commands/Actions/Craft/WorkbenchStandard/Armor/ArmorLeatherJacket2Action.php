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
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс для отображения крафта "Кожаная куртка" (LeatherJacket).
 * Показывает требования (золото, крафтовые предметы, сырьевые ресурсы),
 * рассчитывает, сколько штук можно скрафтить, и генерирует inline-кнопки с вариантами.
 */
class ArmorLeatherJacket2Action extends BaseAction
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
    private CraftCardHelper $craftCardHelper;

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
        $this->craftCardHelper      = new CraftCardHelper();
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

        // Проверяем наличие Верстака 1 уровня (пример)
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

        // Ищем «LeatherJacket» в таблице outfits
        $outfit = $this->outfitModel
            ->where('name_en', 'LeatherJacket')
            ->first();
        if (!$outfit) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдена запись с name_en='LeatherJacket' в таблице outfits.",
            ]);
        }

        // ------- Требования к крафту -------
        $requiredGold = 700; // Пример: 700 золотых
        // Сырьевые ресурсы (из таблиц `resources` и `character_resources`)
        // Если хотите использовать строго name_en, замените 'name' => 'Кожа животных' на getResourceByNameEn(...)
        $requiredRawResources = [
            ['name' => 'Кожа животных', 'qty' => 4], // Из таблицы resources (ID=49)
            ['name' => 'Древесина',     'qty' => 2], // Из таблицы resources (ID=2)
        ];
        // Крафтовые предметы (из crafted_items / crafted_items_log)
        $requiredCraftedItems = [
            'Складной нож' => 1,
            'Ткань' => 8,

        ];

        // Собираем данные игрока
        $goldAmount = (int) $character['gold'];

        // Проверка золота (для 1 шт.)
        $insufficientDetails = [];
        if ($goldAmount < $requiredGold) {
            $insufficientDetails[] = "Золото: нужно {$requiredGold}, у вас {$goldAmount}";
        }

        // 1) Проверяем крафтовые предметы
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

        // 2) Проверяем сырьевые ресурсы — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $haveRawResources = [];
        $rawResourcesForHelper = array_column($requiredRawResources, 'qty', 'name');
        foreach ($this->craftCardHelper->available($character['id'], $rawResourcesForHelper) as $res) {
            $rName   = $res['name'];
            $rQty    = (int) ($rawResourcesForHelper[$rName] ?? 0);
            $haveQty = $res['quantity'];
            if ($haveQty < $rQty) {
                $insufficientDetails[] = "{$rName}: нужно {$rQty}, у вас {$haveQty}";
            }
            $haveRawResources[$rName] = $haveQty;
        }

        // ------- Формируем описание брони из outfits -------
        $nameSafe  = $this->escapeMarkdown($outfit['name'] ?? '???');
        $descRaw   = $outfit['description'] ?? '';
        $descSafe  = $this->escapeMarkdown($descRaw);

        $rarity     = $outfit['rarity']              ?? 'Common';
        $armorVal   = $outfit['armor_value']         ?? 0;
        $physRes    = $outfit['physical_resistance'] ?? 0;
        $fireRes    = $outfit['fire_resistance']     ?? 0;
        $poisRes    = $outfit['poison_resistance']   ?? 0;
        $speedMod   = $outfit['speed_modifier']      ?? 0;
        $stealthMod = $outfit['stealth_modifier']    ?? 0;
        $weight     = $outfit['weight']              ?? 0;
        $dura       = $outfit['durability']          ?? 0;
        $duraMax    = $outfit['durability_max']      ?? 100;
        $reqStr     = $outfit['required_strength']   ?? 0;
        $reqLvl     = $outfit['required_level']      ?? 1;
        $price      = $outfit['price']               ?? 0;

        // Описание предмета
        $text = "🧥 *{$nameSafe}*\n"
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

        // Проверяем, можем ли скрафтить хотя бы 1 шт.
        $maxCraftable = PHP_INT_MAX;

        // Лимит по золоту
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        $maxCraftable = min($maxCraftable, $maxByGold);

        // Лимит по крафтовым предметам
        foreach ($requiredCraftedItems as $nm => $reqQty) {
            $haveQty = $haveCrafted[$nm] ?? 0;
            $localMax = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Лимит по сырьевым ресурсам
        foreach ($requiredRawResources as $resInfo) {
            $rName    = $resInfo['name'];
            $needQty  = $resInfo['qty'];
            $haveQty  = $haveRawResources[$rName] ?? 0;
            $localMax = (int) floor($haveQty / $needQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Если не можем скрафтить даже 1 шт.
        if ($maxCraftable < 1) {
            $text .= "\nНедостаточно ресурсов для крафта хотя бы *1 шт.*\n";
            if (!empty($insufficientDetails)) {
                $text .= "\n*Не хватает:*";
                foreach ($insufficientDetails as $line) {
                    $text .= "\n- " . $this->escapeMarkdown($line);
                }
                $text .= "\n";
            }

            // Выход из тупика ведёт туда же, куда обычная кнопка "Крафт 1шт" этой
            // карточки — на свой стартовый класс StartCraftLeatherJacket2Action, а не
            // на общий genericCraft_.
            $fallback = $this->craftCardHelper->fallbackButton('LeatherJacket2');
            $fallback['callback_data'] = 'startCraftLeatherJacket2_1';

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍 Купить',    'callback_data' => 'buy'],
                    ],
                    [
                        $fallback,
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'armorCraft2'],
                    ],
                ],
            ];
        } else {
            // Можно крафтить
            $text .= "\n*Для крафта 1 шт.* нужно:\n";
            foreach ($requiredCraftedItems as $nm => $qt) {
                $text .= " - {$this->escapeMarkdown($nm)} x {$qt}\n";
            }
            foreach ($requiredRawResources as $resInfo) {
                $rName = $resInfo['name'];
                $rQty  = $resInfo['qty'];
                $text .= " - {$this->escapeMarkdown($rName)} x {$rQty}\n";
            }
            $text .= " - Золото x {$requiredGold}\n\n";
            $text .= "Примерное время крафта (за 1 шт.): ~10-12 минут\n";

            // Генерируем кнопки с доступным количеством
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q} шт",
                        'callback_data' => "startCraftLeatherJacket2_{$q}",
                    ];
                }
            }

            $rows = array_chunk($quantityButtons, 3);
            // Добавляем нижний ряд
            $rows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍 Купить',    'callback_data' => 'buy'],
            ];

            $rows[] = [['text' => '⬅️ Назад', 'callback_data' => 'armorCraft2']];

            $keyboard = ['inline_keyboard' => $rows];
        }

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Путь к картинке (подставьте свою)
        $imagePath = base_url('uploads/telegram/craft/standard/leather_jacket.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Если нет базы или верстака, выводим ошибочное сообщение.
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
