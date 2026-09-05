<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\SalesModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;

class BuyCraftItemListAction extends BaseAction
{
    protected $salesModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->salesModel = new SalesModel();
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

        // Гейты входа — единым сервисом (порог золота был захардкожен константой 1000
        // и расходился бы с админской настройкой). См. CraftShopGate.
        $gate = (new \App\Services\Economy\CraftShopGate())->check($character);
        if ($gate !== null) {
            $this->logRejected($character['id'], 'BUY_CRAFT', $gate['reason']);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $gate['text'],
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🛒 Магазин',   'callback_data' => 'shop'],
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
                ]]]),
            ]);
        }

        // Извлечение типа предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        $type = str_replace('buyCraftList_', '', $callbackData);

        // Получение всех предметов данного типа у торговца
        $salesItems = $this->salesModel->findAll();
        $craftedItemsList = [];
        foreach ($salesItems as $item) {
            $craftedItem = $this->craftedItemsModel->find($item['crafted_item_id']);
            if ($craftedItem && $craftedItem['type'] === $type) {
                $craftedItemsList[] = [
                    'id' => $craftedItem['id'],
                    'name' => $craftedItem['name_rus'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ];
            }
        }

        if (empty($craftedItemsList)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'В данный момент у торговца нет предметов данного типа для продажи.',
            ]);
        }

        // Формирование сообщения и кнопок
        $typeRus = $this->translateType($type);
        $text = "*Ты собираешься купить:*\n";
        $text .= "Направление: {$typeRus}\n";

        // Показываем ту цену, которую реально спишут: BuyCraftConfirmAction берёт
        // `base × buyMultiplier`, а у множителя покупки ПОЛ 1.25 — то есть сырое
        // `sales.price` занижало счёт минимум на четверть (см. CraftTradeService).
        $craftTrade = new \App\Services\Economy\CraftTradeService();
        $karma      = $craftTrade->karmaOf($character);

        $keyboardButtons = [];
        foreach ($craftedItemsList as $index => $item) {
            $basePrice = is_numeric($item['price']) ? (float) $item['price'] : 0.0;
            $unit      = $craftTrade->displayBuyPrice($basePrice, $karma);
            $text .= "- *№" . ($index + 1) . "* / _" . $item['name'] . "_ / *" . $item['quantity'] . "* в наличии / *" . $unit . " 💰* за шт.\n";
            $keyboardButtons[] = [
                'text' => (string)($index + 1),
                'callback_data' => 'buyCraftItem_' . $item['id']
            ];
        }

        $text .= "\n" . $craftTrade->buyPriceHint() . "\n";

        $keyboardButtons[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];

        $keyboard = array_chunk($keyboardButtons, 3);
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на выбор категории + Магазин.
        $keyboard[] = [
            ['text' => '⬅️ Назад',   'callback_data' => 'buyCraft'],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): список крафта категории у торговца — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function translateType($type)
    {
        // Единый словарь категорий — см. CraftTypeLabels (жил в 4 копиях; здесь покрывал
        // всего 5 типов из 18, остальные показывались игроку сырым ключом).
        return \App\Services\Player\Trade\CraftTypeLabels::rus((string) $type);
    }
}
