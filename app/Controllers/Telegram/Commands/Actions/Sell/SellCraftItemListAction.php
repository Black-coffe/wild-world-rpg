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

        // ADR-165 — виртуальные категории экипировки читают свой источник
        // (`characters_weapons` / `characters_outfits`), а не `crafted_items_log`.
        $gearSale = new \App\Services\Economy\GearSaleService();
        $gearKind = $gearSale->kindForCategory($type);
        if ($gearKind !== null) {
            return $this->handleGear($chatId, $character, $type, $gearKind, $gearSale);
        }

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

    /**
     * ADR-165 — список продаваемой экипировки категории.
     *
     * Показываем ровно ту цену, которую торговец заплатит за штуку: доля от базовой
     * цены предмета, карма и складской бонус — те же, что на крафте. Игрок не должен
     * узнавать реальную сумму только на экране подтверждения.
     *
     * @param array<string,mixed> $character
     */
    private function handleGear(
        int|string $chatId,
        array $character,
        string $category,
        string $kind,
        \App\Services\Economy\GearSaleService $gearSale
    ): ServerResponse {
        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if (! $gearSale->isEnabled()) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Скупка экипировки сейчас закрыта — торговец берёт только крафт и сырьё.',
            ]);
        }

        $items = $gearSale->sellable($characterId, $kind);
        if ($items === []) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Свободной экипировки этого вида у тебя нет.\n\n"
                    . "_Надетое торговец не берёт — сними вещь, если решил продать. "
                    . "Трофеи с меткой 🔒 он не берёт вовсе._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $karmaRaw      = $character['trading_karma'] ?? 0;
        $karma         = is_numeric($karmaRaw) ? (float) $karmaRaw : 0.0;
        $warehouseMult = (new \App\Services\Player\WarehouseSellBonusService())->sellPriceMultiplier($characterId);
        $code          = $gearSale->codeForKind($kind);

        $typeRus = $this->translateType($category);
        $text    = "Категория: *{$typeRus}*\n\n";

        $keyboardButtons = [];
        foreach ($items as $index => $item) {
            $unit  = (int) round($gearSale->unitPrice($item['base_price'], $karma, $warehouseMult));
            $text .= '– *' . ($index + 1) . '* ' . $item['name']
                . ' | ' . $item['quantity'] . " ед. | {$unit}💰 за шт.\n";

            $keyboardButtons[] = [
                'text'          => (string) ($index + 1),
                'callback_data' => "sellGearItem_{$code}_{$item['row_id']}",
            ];
        }

        $text .= "\n_Торговец берёт экипировку по цене подержанной вещи — заметно ниже, "
            . "чем стоит новая._\n"
            . "_Надетое и трофеи с 🔒 в списке не показаны._\n\n"
            . "_Что будешь продавать?_\n";

        $keyboard   = array_chunk($keyboardButtons, 4);
        $keyboard[] = [
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];
        $keyboard[] = [
            ['text' => '⬅️ Назад',   'callback_data' => 'sellCraft'],
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
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
