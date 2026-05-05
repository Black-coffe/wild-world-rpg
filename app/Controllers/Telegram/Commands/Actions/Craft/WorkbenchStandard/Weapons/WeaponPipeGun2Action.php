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
 * Класс для вывода информации по крафту "Трубчатый пистолет" (PipeGun).
 * Показывает требования (золото, крафтовые предметы, сырьевые ресурсы, силу/уровень),
 * и генерирует Inline-кнопки (сколько шт. скрафтить).
 */
class WeaponPipeGun2Action extends BaseAction
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
     * Возможные варианты крафта (количество).
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

        // Проверка: не идёт ли переезд
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

        // Проверяем наличие Верстака 1 уровня (WorkbenchOne), если по игре нужно
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

        // Ищем «PipeGun» в таблице weapons
        $weapon = $this->weaponModel->getByEnglishName('PipeGun');
        if (!$weapon) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❓ Не найдена запись с name_en='PipeGun' в таблице weapons.",
            ]);
        }

        // --- Устанавливаем требования к крафту ---
        // (Можно брать из $weapon, либо прописать вручную)

        // 1) золото
        $requiredGold = 600;  // пример: 600 золота
        // 2) сырьевые ресурсы (пример)
        $requiredRawResources = [
            ['name' => 'Древесина',       'qty' => 2],
            ['name' => 'Кожа животных',       'qty' => 2],
            ['name' => 'Смола деревьев',       'qty' => 2],
        ];
        // 3) крафтовые предметы
        $requiredCraftedItems = [
            'Складной нож'  => 1,
            'Металл фрагменты' => 4,
            'Ткань' => 4,
        ];

        // Минимальные силы/уровня — берём или из weapons:
        $strengthRequired = max(0, (int)$weapon['required_strength']); // 'PipeGun' = 0 + ...
        $levelRequired    = max(1, (int)$weapon['required_level']);    // 'PipeGun' = lvl=2 -> min(1,2)...

        // Собираем «недостатки»
        $insufficientDetails = [];

        // Проверяем силу
        if ($character['strength'] < $strengthRequired) {
            $insufficientDetails[] = "Требуется сила {$strengthRequired}, у вас {$character['strength']}";
        }

        // Проверяем уровень
        if ($character['level'] < $levelRequired) {
            $insufficientDetails[] = "Требуется уровень {$levelRequired}, у вас {$character['level']}";
        }

        // Золото
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

        // --- Формируем описание оружия (из weapons)
        $nameSafe  = $this->escapeMarkdown($weapon['name'] ?? '???');
        $descRaw   = $weapon['description'] ?? '';
        $descSafe  = $this->escapeMarkdown($descRaw);

        $rarity     = $weapon['rarity']       ?? 'Common';
        $damage     = $weapon['damage_value'] ?? 0;
        $dType      = $weapon['damage_type']  ?? 'Physical';
        $rangeVal   = $weapon['range_value']  ?? 1;
        $atkSpeed   = $weapon['attack_speed'] ?? 1;
        $weight     = $weapon['weight']       ?? 0;
        $durabMax   = $weapon['durability_max'] ?? 100;
        $reqStr     = max($weapon['required_strength'], $strengthRequired);
        $reqLvl     = max($weapon['required_level'], $levelRequired);
        $weaponPrice= $weapon['price'] ?? 0;

        // Текст
        $text  = "🔫 *{$nameSafe}*\n";
        $text .= "_{$descSafe}_\n\n";
        $text .= "*Редкость:* `{$rarity}`\n";
        $text .= "*Урон:* `{$damage}` ({$dType})\n";
        $text .= "*Дальность:* `{$rangeVal}`\n";
        $text .= "*Скорость атаки:* `{$atkSpeed}`\n";
        $text .= "*Макс. прочность:* `{$durabMax}`\n";
        $text .= "*Вес:* `{$weight}`\n";
        $text .= "*Треб. сила:* `{$reqStr}`\n";
        $text .= "*Треб. уровень:* `{$reqLvl}`\n";
        $text .= "*Цена (продажи):* `{$weaponPrice}`\n";

        // Если есть несоответствия, сообщаем
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

            // Картинка "pipe_gun.jpg" (поставьте свою)
            $imagePath = base_url('uploads/telegram/craft/standard/pipe_gun.jpg');
            return Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Иначе считаем, сколько можно скрафтить максимум
        $maxCraftable = PHP_INT_MAX;

        // Лимит по золоту
        $maxByGold = (int) floor($goldAmount / $requiredGold);
        $maxCraftable = min($maxCraftable, $maxByGold);

        // Лимит по крафтовым предметам
        foreach ($requiredCraftedItems as $nm => $reqQty) {
            $haveQty  = $haveCrafted[$nm] ?? 0;
            $localMax = (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Лимит по сырьевым ресурсам
        foreach ($requiredRawResources as $resInfo) {
            $rName   = $resInfo['name'];
            $reqQty  = $resInfo['qty'];
            $haveQty = $haveRawResources[$rName] ?? 0;
            $localMax= (int) floor($haveQty / $reqQty);
            $maxCraftable = min($maxCraftable, $localMax);
        }

        // Рассказываем игроку требования
        $text .= "\n*Для крафта 1 шт.* нужно:\n";
        foreach ($requiredCraftedItems as $nm => $qt) {
            $nSafe = $this->escapeMarkdown($nm);
            $text .= " - {$nSafe} x {$qt}\n";
        }
        foreach ($requiredRawResources as $ri) {
            $rSafe = $this->escapeMarkdown($ri['name']);
            $text .= " - {$rSafe} x {$ri['qty']}\n";
        }
        $text .= " - Золото x {$requiredGold}\n\n";
        $text .= "Примерное время крафта (за 1 шт.): ~8-12 минут\n";

        // Кнопки
        if ($maxCraftable < 1) {
            // Недостаёт ресурсов даже на 1
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
            // Генерируем кнопки "Крафт X шт."
            $quantityButtons = [];
            foreach ($this->craftQuantities as $q) {
                if ($q <= $maxCraftable) {
                    $quantityButtons[] = [
                        'text' => "🛠 Крафт {$q}шт",
                        'callback_data' => "genericCraft_PipeGun_{$q}",
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
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Картинка "pipe_gun.jpg"
        $imagePath = base_url('uploads/telegram/craft/standard/pipe_gun.jpg');
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Если нет базы/верстака, отвечаем отдельно.
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
