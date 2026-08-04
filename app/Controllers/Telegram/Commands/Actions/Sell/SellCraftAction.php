<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class SellCraftAction extends BaseAction
{
    protected $characterModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel       = new CharacterModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
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

        $craftedItemsLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->findAll();

        // Получение всех типов крафтовых предметов у персонажа
        $craftedItems = [];
        foreach ($craftedItemsLog as $log) {
            $craftedItem = $this->craftedItemsModel->find($log['crafted_item_id']);
            if ($craftedItem) {
                $type = $craftedItem['type'] ?? 'unknown';
                if (!isset($craftedItems[$type])) {
                    $craftedItems[$type] = [
                        'type' => $type,
                        'type_rus' => $this->translateType($type),
                    ];
                }
            }
        }

        // ADR-165 — виртуальные категории экипировки. Оружие и броня живут в
        // `characters_weapons` / `characters_outfits` и в `crafted_items_log` не попадают
        // НИКОГДА, поэтому источник списка здесь отдельный. Ранний return «нет скрафченных
        // ресурсов» снят выше по этой же причине: игрок с полным арсеналом и пустым логом
        // крафта не увидел бы ни одной категории.
        $gearSale = new \App\Services\Economy\GearSaleService();
        if ($gearSale->isEnabled()) {
            $gearCategories = [
                \App\Services\Economy\GearSaleService::CATEGORY_WEAPON => \App\Services\Economy\GearSaleService::KIND_WEAPON,
                \App\Services\Economy\GearSaleService::CATEGORY_ARMOR  => \App\Services\Economy\GearSaleService::KIND_OUTFIT,
            ];
            foreach ($gearCategories as $category => $kind) {
                if ($gearSale->hasSellable((int) $character['id'], $kind)) {
                    $craftedItems[$category] = [
                        'type'     => $category,
                        'type_rus' => $this->translateType($category),
                    ];
                }
            }
        }

        if (empty($craftedItems)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Продавать пока нечего: ни крафта, ни свободной экипировки.\n\n"
                    . "Надетое оружие и броня в продажу не идут — сними их в «Арсенале» или "
                    . "«Гардеробе», если решил сбыть. Трофеи с меткой 🔒 торговец не берёт вовсе.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ],
                    ],
                ]),
            ]);
        }

        // Формирование сообщения и кнопок
        $text = "*Привет друг!* \n"
            . "Ты зашел ко мне в лавку и решил продать немного своего добра, "
            . "которое ты так усердно крафтил.\n\n"
            . "Ну что ж, показывай, что там у тебя есть?\n";

        $keyboardButtons = [];

        foreach ($craftedItems as $type => $item) {
            $keyboardButtons[] = [
                'text' => $item['type_rus'],
                'callback_data' => 'sellCraftList_' . $type
            ];
        }

        // Добавим кнопки персонаж / инвентарь
        $keyboardButtons[] = ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];

        $keyboard = array_chunk($keyboardButtons, 2);
        // Arseny report 2026-05-26: «Нужна кнопка назад» — шаг назад на главный экран магазина.
        $keyboard[] = [
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
        ];

        $imagePath = base_url('uploads/telegram/craft/vendor_kiosk_in_the_game_world.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    /**
     * Переводим type -> человекопонятное название с эмоджи.
     */
    private function translateType($type)
    {
        // Единый словарь категорий (был в 4 копиях, покрывал 7 типов из 18 — игрок
        // видел кнопки «drones» / «❓utility»).
        return \App\Services\Player\Trade\CraftTypeLabels::rus((string) $type);
    }
}
