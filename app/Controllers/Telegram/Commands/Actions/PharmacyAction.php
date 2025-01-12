<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;

class PharmacyAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items.name_rus, crafted_items.name_eng, crafted_items.character_boost')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where([
                'crafted_items_log.character_id' => $character['id'],
                'crafted_items.type' => 'drug'
            ])
            ->findAll();

        if (empty($craftedItemsLogs)) {
            $text = "К сожалению, у тебя нет медицинских предметов! Нужно их сначала скрафтить.";
            $inline_keyboard[] = ['text' => '🛠️ Крафтинг', 'callback_data' => 'crafting'];
            $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];
        } else {
            $text = "🔥 *Исцели свои раны и зарядись силой!* 🔥\n\n*У тебя в наличии:*\n\n";
            $inline_keyboard = [];

            foreach ($craftedItemsLogs as $item) {
                // Очистка данных от невидимых символов
                $cleanedBoost = preg_replace('/[[:cntrl:]]/', '', $item['character_boost']);
                $cleanedBoost = str_replace(' ', ' ', $cleanedBoost); // Замена неразрывных пробелов на обычные

                $boost = json_decode($cleanedBoost, true);
                $boostText = '';

                if (is_array($boost) && !empty($boost)) {
                    foreach ($boost as $effects) {
                        foreach ($effects as $effectName => $effectValue) {
                            $boostText .= "{$effectName}: {$effectValue}, ";
                        }
                    }
                    $boostText = rtrim($boostText, ', ');
                }

                $text .= "📋 *{$item['name_rus']}* | {$item['quantity']} шт.\n *Баф:* {$boostText}\n\n";
                $inline_keyboard[] = ['text' => $item['name_rus'], 'callback_data' => 'usePharmacy_' . $item['name_eng']];
            }

            $text .= "\n_Выбери снизу, какой предмет ты будешь использовать:_👇";
            $inline_keyboard[] = ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'];

            $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];
        }

        $imagePath = base_url('uploads/telegram/craft/many_medicinal_things.jpg'); // Укажите актуальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
