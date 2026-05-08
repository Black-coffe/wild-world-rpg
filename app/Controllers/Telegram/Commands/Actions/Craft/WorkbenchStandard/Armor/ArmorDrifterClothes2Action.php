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
use App\Models\OutfitModel; // <-- модель для таблицы outfits
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Пример класса для отображения крафта "Одежда бродяги" (DrifterClothes),
 * c экранированием Markdown и выводом списка недостающих ресурсов.
 */
class ArmorDrifterClothes2Action extends BaseAction
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
     * Возможные варианты крафта (количество).
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

        // Проверка, нет ли активного переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверяем наличие базы
        $base = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->first();
        if (!$base) {
            return $this->sendInsufficientResponse($chatId, 'У вас нет построенной базы (лагеря).');
        }

        // Проверяем наличие Верстака 1 уровня (пример)
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

        // Допустим, в таблице outfits у нас "WandererClothes"
        // (если хотим строго 'DrifterClothes', замените на это имя)
        $outfit = $this->outfitModel
            ->where('name_en', 'WandererClothes')
            ->first();

        if (!$outfit) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдено описание для DrifterClothes в таблице outfits.",
            ]);
        }

        // Требования для 1 шт.
        $requiredGold = 500;
        $requiredComponents = [
            'Ткань'          => 8,  // "Fabric"
            'Складной нож' => 1,  // "Leather"
        ];

        // Проверяем сколько всего у игрока
        $goldAmount = (int) $character['gold'];

        // Для детального списка недостающих
        $insufficientDetails = [];

        // 1) Проверяем золото (хотя бы на 1 шт.)
        if ($goldAmount < $requiredGold) {
            $insufficientDetails[] = "Золото: нужно {$requiredGold}, у вас {$goldAmount}";
        }

        // 2) Проверка крафтовых компонентов
        $haveResources = [];
        foreach ($requiredComponents as $itemName => $reqQty) {
            // Ищем предмет по названию (рус/англ)
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                // Нет самого предмета в БД
                $insufficientDetails[] = "❓ {$itemName} (нет в БД crafted_items)";
                continue;
            }

            // Сколько у персонажа
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int)$logRow['quantity'] : 0;
            $haveResources[$itemName] = $haveQty;

            // Если не хватает хотя бы на 1 шт.
            if ($haveQty < $reqQty) {
                $insufficientDetails[] = "{$itemName}: нужно {$reqQty}, у вас {$haveQty}";
            }
        }

        // Достаём свойства из outfits
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
        $price      = $outfit['price']              ?? 0;

        // Формируем описание (с учётом экранирования)
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

        // Считаем, сколько максимум шт. можно скрафтить
        $maxCraftable = PHP_INT_MAX;

        // Лимит по золоту
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        if ($maxByGold < $maxCraftable) {
            $maxCraftable = $maxByGold;
        }

        // Лимит по каждому ресурсу
        foreach ($requiredComponents as $itemName => $reqQty) {
            // Если предмет не найден, значит точно 0
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

        // Если не можем скрафтить даже 1 шт.
        if ($maxCraftable < 1) {
            $text .= "\nНедостаточно ресурсов для крафта даже *1шт.*\n";

            // Если есть конкретные «недостатки»
            if (!empty($insufficientDetails)) {
                $text .= "\n*Не хватает:*";
                foreach ($insufficientDetails as $line) {
                    // тоже экранируем
                    $text .= "\n- ".$this->escapeMarkdown($line);
                }
                $text .= "\n";
            }

            // Формируем клавиатуру
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            // Можно скрафтить хотя бы 1
            $text .= "\n*Для крафта 1шт:* \n";
            foreach ($requiredComponents as $nm => $qt) {
                $nSafe = $this->escapeMarkdown($nm);
                $text .= " - {$nSafe} x {$qt}\n";
            }
            $text .= " - Золото x {$requiredGold}\n\n";
            $text .= "Примерное время крафта (за 1 шт.): ~8 минут\n";

            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q}шт",
                        'callback_data' => "startCraftDrifterClothes2_{$q}",
                    ];
                }
            }

            $rows = array_chunk($quantityButtons, 3);

            $rows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
            ];

            $keyboard = ['inline_keyboard' => $rows];
        }

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Путь к картинке
        $imagePath = base_url('uploads/telegram/craft/standard/drifter_clothes.jpg');

        // Отправка фото с подписью (parse_mode Markdown)
        // Если подпись слишком длинная (>1024 символа), нужно разбить на несколько сообщений
        $response = \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        return $response;
    }

    /**
     * Выводит сообщение об отсутствии базы или верстака.
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
     * Экранирование спецсимволов Markdown (в т.ч. эмодзи-иногда).
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
