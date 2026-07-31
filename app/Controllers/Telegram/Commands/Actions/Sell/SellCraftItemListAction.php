<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;

class SellCraftItemListAction extends BaseAction
{
    protected $characterModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
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

        // Извлечение типа предмета из колбека
        $callbackData = $this->callbackQuery->getData();
        $type = str_replace('sellCraftList_', '', $callbackData);

        // Получение всех крафтовых предметов данного типа у персонажа
        $craftedItemsLog = $this->craftedItemsLogModel->where('character_id', $character['id'])->findAll();
        $craftedItems = [];
        foreach ($craftedItemsLog as $log) {
            $craftedItem = $this->craftedItemsModel->find($log['crafted_item_id']);
            if ($craftedItem && $craftedItem['type'] === $type) {
                if (isset($craftedItem['name_rus'], $craftedItem['price'])) {
                    $craftedItems[] = [
                        'id' => $log['crafted_item_id'],
                        'name' => $craftedItem['name_rus'],
                        'quantity' => $log['quantity'],
                        'price' => $craftedItem['price']
                    ];
                }
            }
        }

        if (empty($craftedItems)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет крафтовых предметов этого типа для продажи.',
            ]);
        }

        // Перевод типа предмета на русский
        $typeRus = $this->translateType($type);

        // Формирование сообщения и кнопок
        $text = "Категория: *{$typeRus}*\n\n";
        foreach ($craftedItems as $index => $item) {
            $text .= "– *" . ($index + 1) . "* " . $item['name'] . " | " . $item['quantity'] . " ед. | " . $item['price'] . "💰\n";
        }
        $text .= "\n_Что будешь продавать?_\n";

        // Формирование кнопок с цифрами по 4 в ряд
        $keyboardButtons = [];
        foreach ($craftedItems as $index => $item) {
            $keyboardButtons[] = [
                'text' => (string)($index + 1),
                'callback_data' => 'sellCraftItem_' . $item['id']
            ];
        }

        // Разбиваем кнопки на ряды по 4 штуки
        $keyboard = array_chunk($keyboardButtons, 4);

        // Добавляем остальные кнопки по 2 в ряд
        $keyboard[] = [['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'], ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']];
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на выбор категории + Магазин.
        $keyboard[] = [
            ['text' => '⬅️ Назад',   'callback_data' => 'sellCraft'],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): список крафта категории на продажу — навигация →
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
