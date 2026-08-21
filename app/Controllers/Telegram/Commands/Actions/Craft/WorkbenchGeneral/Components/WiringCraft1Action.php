<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class WiringCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Возможные количества крафта.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        // 1) Основные проверки
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: пользователь или персонаж не найден.'
            ]);
        }

        // Проверка переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // 2) Узнаём, сколько уже есть "Проводки" (wiring) у игрока
        $wiringQty = $this->getCraftedItemQuantity($character['id'], 'wiring');

        $title = '🔌 Проводка';
        if ($wiringQty > 0) {
            $title .= " (в инв. – {$wiringQty} шт.)";
        }

        // 3) Что нужно на 1 шт.
        // Допустим, для "изоляции" → Мхи (обычный ресурс)
        // А для "проводящей части" → metalFragments (крафтовый предмет)
        $required = [
            'resources' => [
                'Мхи' => 2,
            ],
            'crafted_items' => [
                'metalFragments' => 3,
            ],
        ];

        // Проверяем, сколько есть
        $avail = $this->checkResourcesAndCraftedItems($character['id'], $required);

        // Считаем, на сколько шт. реально хватит
        $maxCraftable = $this->calculateMaxCraftableItems($avail, $required);

        // 4) Формируем описание
        $text = "*{$title}*\n\n"
            . "Данный компонент используется для соединения электрических цепей.\n\n"
            . "На *1 шт.* требуется:\n\n";

        // 4.1) Обычные ресурсы (например, «Мхи»)
        foreach ($required['resources'] as $resName => $need) {
            $have = $avail['resources'][$resName]['quantity'] ?? 0;
            $rar  = $avail['resources'][$resName]['rarity']   ?? '?';
            $text .= "- {$resName}: нужно {$need}, есть {$have}, редк. {$rar}\n";
        }

        // 4.2) Крафтовые предметы (например, «metalFragments» -> «Металл фрагменты»)
        foreach ($required['crafted_items'] as $itemEng => $need) {
            $have      = $avail['crafted_items'][$itemEng]['quantity']     ?? 0;
            $dispName  = $avail['crafted_items'][$itemEng]['display_name'] ?? $itemEng;
            $text     .= "- {$dispName}: нужно {$need} шт., в наличии {$have} шт.\n";
        }

        $text .= "\n*Время крафта (1 шт.):* ~10–15 мин.\n"
            . "*Цена:* ~200💰\n"
            . "_Прерывание задачи ведёт к потере ресурсов._";

        // 5) Клавиатура
        if ($maxCraftable < 1) {
            $text .= "\n\n_Недостаточно ресурсов/предметов для 1 шт._";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('Wiring'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Есть возможность крафта
            $qtyButtons = $this->makeQuantityButtons($maxCraftable);
            $rows       = array_chunk($qtyButtons, 3);

            // Добавим 2 ряда
            $rows[] = [
                ['text' => '👤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $rows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $rows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];

            $keyboard = ['inline_keyboard' => $rows];
        }

        // 6) Отправляем фото + текст
        $imagePath = base_url('uploads/telegram/craft/components/wiring_craft.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // ==================== Вспомогательные методы ====================

    /**
     * Возвращает количество уже имеющейся "wiring" (name_eng) у игрока.
     */
    private function getCraftedItemQuantity(int $charId, string $itemEng): int
    {
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemEng, $charId);
        return $row ? (int)$row['quantity'] : 0;
    }

    /**
     * Проверяем, сколько у игрока обычных ресурсов (по 'name') и крафтовых предметов (по 'name_eng').
     * Возвращаем структуру, где для каждого ресурса/предмета есть [quantity], [rarity], [display_name], ...
     */
    private function checkResourcesAndCraftedItems(int $charId, array $required): array
    {
        $result = [
            'resources' => [],
            'crafted_items' => [],
        ];

        // 1) Обычные ресурсы — тем же пулом (рюкзак + склад базы, ADR-171), которым потом
        // считает старт крафта GenericCraftActionStart::checkResources().
        foreach ($this->craftCardHelper->available($charId, $required['resources']) as $res) {
            $result['resources'][$res['name']] = [
                'quantity' => $res['quantity'],
                'rarity'   => $res['rarity'],
            ];
        }

        // 2) Крафтовые предметы
        foreach ($required['crafted_items'] as $itemEng => $need) {
            // Смотрим в таблице crafted_items, чтобы получить name_rus
            $itemData = $this->craftedItemsModel->where('name_eng', $itemEng)->first();
            $rusName  = $itemData ? $itemData['name_rus'] : $itemEng;

            // Смотрим в crafted_items_log, сколько у игрока
            $logRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemEng, $charId);
            $have   = $logRow ? (int)$logRow['quantity'] : 0;

            $result['crafted_items'][$itemEng] = [
                'quantity'     => $have,
                'display_name' => $rusName, // тут можно хранить "Металл фрагменты"
            ];
        }

        return $result;
    }

    /**
     * Определяем, на сколько штук хватит ресурсов (минимум по всем позициям).
     */
    private function calculateMaxCraftableItems(array $avail, array $required): int
    {
        $maxCraftable = PHP_INT_MAX;

        // 1) resources
        foreach ($required['resources'] as $resName => $need) {
            $have = $avail['resources'][$resName]['quantity'] ?? 0;
            $possible = ($need > 0) ? intdiv($have, $need) : 0;
            if ($possible < $maxCraftable) {
                $maxCraftable = $possible;
            }
        }

        // 2) crafted_items
        foreach ($required['crafted_items'] as $itemEng => $need) {
            $have = $avail['crafted_items'][$itemEng]['quantity'] ?? 0;
            $possible = ($need > 0) ? intdiv($have, $need) : 0;
            if ($possible < $maxCraftable) {
                $maxCraftable = $possible;
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Формируем кнопки "🛠 Крафт X шт."
     */
    private function makeQuantityButtons(int $maxItems): array
    {
        $btns = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxItems) {
                $btns[] = [
                    'text' => "🛠 {$q} шт",
                    'callback_data' => "genericCraft_Wiring_{$q}"
                ];
            }
        }
        return $btns;
    }
}
