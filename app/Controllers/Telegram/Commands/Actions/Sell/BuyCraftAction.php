<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\SalesModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class BuyCraftAction extends BaseAction
{
    protected $salesModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->salesModel        = new SalesModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Навигация для экранов-ошибок (тупики без кнопок → возврат к персонажу/магазину).
        $backNav = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
                ],
            ],
        ]);

        // Гейты входа (золото + Склад) — единым сервисом на все четыре экрана лавки:
        // порог золота live-tunable, и раньше его читал ТОЛЬКО этот экран.
        $gate = (new \App\Services\Economy\CraftShopGate())->check($character);
        if ($gate !== null) {
            $this->logRejected($character['id'], 'BUY_CRAFT', $gate['reason']);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $gate['text'],
                'parse_mode'   => 'Markdown',
                'reply_markup' => $backNav,
            ]);
        }

        // Список товаров, имеющихся у торговца
        $salesItems = $this->salesModel->findAll();
        if (empty($salesItems)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'В данный момент у торговца нет доступных крафтовых предметов для продажи.',
                'reply_markup' => $backNav,
            ]);
        }

        // Группируем товары по их type (из crafted_items)
        $itemTypes = [];
        foreach ($salesItems as $item) {
            $craftedItem = $this->craftedItemsModel->find($item['crafted_item_id']);
            if ($craftedItem) {
                $type = $craftedItem['type'] ?? 'unknown';
                if (!isset($itemTypes[$type])) {
                    $itemTypes[$type] = 0;
                }
                $itemTypes[$type] += $item['quantity'];
            }
        }

        $text = "Привет мой друг, поторгуем?\n\n"
            . "Я тут прикупил немного барахла, готов продать по дешевке тебе.\n"
            . "*Вот что у меня есть:*\n";
        $keyboardButtons = [];

        foreach ($itemTypes as $type => $quantity) {
            $typeRus = $this->translateType($type);
            $text .= "- {$typeRus} | {$quantity} всех предметов\n";
            $keyboardButtons[] = [
                'text' => $typeRus,
                'callback_data' => 'buyCraftList_' . $type
            ];
        }

        // Дополнительные кнопки
        $keyboardButtons[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];

        $keyboard = array_chunk($keyboardButtons, 2);
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на главный экран магазина.
        $keyboard[] = [
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        $imagePath = base_url('uploads/telegram/craft/vendor_kiosk_in_the_game_world.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    /**
     * Название категории крафта. Единый словарь — {@see CraftTypeLabels}
     * (жил в 4 копиях и покрывал 7 типов из 18: игрок видел «drones» / «❓utility»).
     */
    private function translateType($type)
    {
        return \App\Services\Player\Trade\CraftTypeLabels::rus((string) $type);
    }
}
