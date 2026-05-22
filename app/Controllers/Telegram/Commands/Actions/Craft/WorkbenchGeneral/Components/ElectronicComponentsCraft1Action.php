<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс ElectronicComponentsCraft1Action:
 * Показывает данные о "Электронных компонентах" и формирует кнопки для крафта (1,5,10,25,50,100).
 */
class ElectronicComponentsCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Возможные варианты количества для крафта.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
    }

    public function handle(): ServerResponse
    {
        // Подтверждаем нажатие кнопки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Проверяем, есть ли пользователь/персонаж
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: пользователь или персонаж не найден.'
            ]);
        }

        // Если у игрока идёт переезд — блокируем крафт
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // Узнаём, сколько "electronicComponents" у него уже есть
        $ownedQty = $this->getCraftedItemQuantity($character['id'], 'electronicComponents');

        // Заголовок
        $title = '💻 Электронные компоненты';
        if ($ownedQty > 0) {
            $title .= " (в инв. – {$ownedQty} шт.)";
        }

        // Требования для 1 шт.
        // Допустим, мы решили, что нужно:
        //  - Обычные ресурсы: «Нефть» (Oil) = 3
        //  - Крафтовые предметы: «metalFragments» = 5
        // Если у вас в базе нет "Пластик", вы можете подменить на «Нефть» (Oil) или другой ресурс.
        $required = [
            'resources' => [
                'Нефть' => 3,      // см. в resources.name="Нефть" (англ. name_en='Oil') c id=40
            ],
            'crafted_items' => [
                'metalFragments' => 5, // см. crafted_items.name_eng="metalFragments" c id=72
            ],
        ];

        // Проверяем, сколько у игрока есть всего
        $avail = $this->checkResourcesAndCraftedItems($character['id'], $required);

        // Считаем, на сколько шт. хватает
        $maxCraftable = $this->calculateMaxCraftable($avail, $required);

        // Формируем описание
        $text = "*{$title}*\n\n"
            . "Для создания *1 шт.* нужны:\n\n";

        // 1) Обычные ресурсы
        foreach ($required['resources'] as $resName => $need) {
            $have = $avail['resources'][$resName]['quantity'] ?? 0;
            $rar  = $avail['resources'][$resName]['rarity']   ?? '?';
            $text .= "- {$resName}: нужно {$need}, в наличии {$have}, редк. {$rar}\n";
        }

        // 2) Крафтовые предметы
        foreach ($required['crafted_items'] as $itemEng => $need) {
            $have     = $avail['crafted_items'][$itemEng]['quantity']     ?? 0;
            $dispName = $avail['crafted_items'][$itemEng]['display_name'] ?? $itemEng;
            $text    .= "- {$dispName}: нужно {$need} шт., есть {$have} шт.\n";
        }

        $text .= "\n*Применение:* Компоненты для электроники.\n"
            . "*Цена:* ~300💰\n"
            . "*Время крафта (1 шт.):* ~15–20 мин.\n\n"
            . "_Прерывание задачи ведёт к потере ресурсов._";

        // Формируем кнопки
        if ($maxCraftable < 1) {
            // Недостаточно даже на 1 шт.
            $text .= "\n_Недостаточно ресурсов/предметов для 1 шт._";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Генерируем кнопки (1,5,10,25,50,100)
            $qtyButtons = $this->makeQuantityButtons($maxCraftable);
            $rows       = array_chunk($qtyButtons, 3);

            // Добавим ещё ряды
            $rows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $rows[] = [
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
            ];
            $rows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];

            $keyboard = ['inline_keyboard' => $rows];
        }

        // Отправляем фото + текст
        $imgPath = base_url('uploads/telegram/craft/components/electronic_components.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imgPath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // ------------------------------------------------------
    // Вспомогательные методы
    // ------------------------------------------------------

    /**
     * Сколько уже имеется крафтового предмета (англ. name_eng) у игрока
     */
    private function getCraftedItemQuantity(int $charId, string $nameEng): int
    {
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($nameEng, $charId);
        return $row ? (int)$row['quantity'] : 0;
    }

    /**
     * Проверяем наличие обычных ресурсов + крафтовых предметов (как в Wiring).
     * Возвращаем структуру типа:
     * [
     *   'resources' => [
     *       'Мхи' => [ 'quantity'=>..., 'rarity'=>... ],
     *       ...
     *   ],
     *   'crafted_items' => [
     *       'metalFragments' => [ 'quantity'=>..., 'display_name'=>... ],
     *       ...
     *   ]
     * ]
     */
    private function checkResourcesAndCraftedItems(int $charId, array $req): array
    {
        $result = [
            'resources'     => [],
            'crafted_items' => [],
        ];

        // 1) Обычные ресурсы
        foreach ($req['resources'] as $resName => $amount) {
            $resRow = $this->resourceModel->where('name', $resName)->first();
            $have   = 0;
            $rar    = '?';
            if ($resRow) {
                // Ищем у персонажа
                $charRes = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $charId);
                $have    = $charRes ? (int)$charRes['quantity'] : 0;
                $rar     = $resRow['rarity'];
            }
            $result['resources'][$resName] = [
                'quantity' => $have,
                'rarity'   => $rar,
            ];
        }

        // 2) Крафтовые предметы
        foreach ($req['crafted_items'] as $itemEng => $amount) {
            $itemData = $this->craftedItemsModel->where('name_eng', $itemEng)->first();
            $rusName  = $itemData ? $itemData['name_rus'] : $itemEng;

            $logRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemEng, $charId);
            $have   = $logRow ? (int)$logRow['quantity'] : 0;

            $result['crafted_items'][$itemEng] = [
                'quantity'     => $have,
                'display_name' => $rusName,
            ];
        }

        return $result;
    }

    /**
     * Считаем минимальное количество, которое можно скрафтить (учитывая все ресурсы и предметы).
     */
    private function calculateMaxCraftable(array $avail, array $req): int
    {
        $max = PHP_INT_MAX;

        // 1) Обычные ресурсы
        foreach ($req['resources'] as $resName => $need) {
            $have = $avail['resources'][$resName]['quantity'] ?? 0;
            if ($need > 0) {
                $possible = intdiv($have, $need);
                if ($possible < $max) {
                    $max = $possible;
                }
            }
        }

        // 2) Крафтовые предметы
        foreach ($req['crafted_items'] as $itemEng => $need) {
            $have = $avail['crafted_items'][$itemEng]['quantity'] ?? 0;
            if ($need > 0) {
                $possible = intdiv($have, $need);
                if ($possible < $max) {
                    $max = $possible;
                }
            }
        }

        return ($max === PHP_INT_MAX) ? 0 : $max;
    }

    /**
     * Формируем кнопки: "🛠 Крафт 1 шт.", "🛠 Крафт 5 шт." и т.д.
     */
    private function makeQuantityButtons(int $maxCraftable): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftable) {
                $buttons[] = [
                    'text' => "🛠 Крафт {$q} шт",
                    'callback_data' => "genericCraft_ElectronicComponents_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
