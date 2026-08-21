<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\ConsumableShelfService;
use App\Services\Player\DebuffService;
use Config\Consumables;

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

        // Выбираем только те предметы, у которых quantity > 0. Весь drug-набор разом —
        // story 02 (pharmacy-split): на полки его раскладывает ConsumableShelfService::split(),
        // а не отдельные WHERE-запросы под каждый экран.
        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items_log.durability_time, crafted_items_log.durability_count AS log_charges, crafted_items.durability_count AS base_charges, crafted_items.name_rus, crafted_items.name_eng, crafted_items.character_boost')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where([
                'crafted_items_log.character_id' => $character['id'],
                'crafted_items.type' => 'drug'
            ])
            ->where('crafted_items_log.quantity >', 0)
            ->findAll();

        $shelfService   = new ConsumableShelfService();
        $split          = $shelfService->split($craftedItemsLogs);
        $medicine       = $split[Consumables::SHELF_MEDICINE];
        $provisionCount = count($split[Consumables::SHELF_PROVISION]);

        // Если лекарств нет, предлагаем перейти к крафту. Кнопка на «Провизия» остаётся
        // видна и здесь (UX-discoverability) — своя пустая полка не тупик.
        if (empty($medicine)) {
            $text = "К сожалению, у тебя нет медицинских предметов! Нужно их сначала скрафтить: "
                . "🔨 Крафт → 💊 Лекарства.";
            $inline_keyboard = [
                ['text' => "🍲 Провизия ({$provisionCount})", 'callback_data' => 'provision'],
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ];
            // ADR-150 Слайс 2: возврат на карточку «Я» (чинит тупик Аптечки). Только при me_hub ON.
            if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
                $inline_keyboard[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
            }
            $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => $text,
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Есть лекарства. Раны, которые не лечатся едой, показываем прямо здесь, вместе с
        // тем, какой предмет их снимает. Это единственный экран, где решение «что применить»
        // принимается, — без списка ран игрок не поймёт, зачем ему лекарства.
        $charIdForDebuffs  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $debuffService     = new DebuffService();
        $activeDebuffs     = $debuffService->active($charIdForDebuffs);
        $activeDebuffLines = [];
        foreach ($activeDebuffs as $debuffRow) {
            $line = $debuffService->describe($debuffRow);
            if ($line !== '') {
                $activeDebuffLines[] = $line;
            }
        }

        $screen = $shelfService->screen(Consumables::SHELF_MEDICINE, $medicine, $activeDebuffLines);

        $text            = $screen['text'] . "\n_Выбери снизу, какой предмет ты будешь использовать:_ 👇";
        $inline_keyboard = $screen['buttons'];

        // UX-discoverability: сосед виден всегда, даже когда там пусто.
        $inline_keyboard[] = ['text' => "🍲 Провизия ({$provisionCount})", 'callback_data' => 'provision'];
        $inline_keyboard[] = [
            'text' => '🧑‍🌾 Действия 🛠️',
            'callback_data' => 'characterActions'
        ];
        // ADR-150 Слайс 2: возврат на карточку «Я» (чинит тупик Аптечки). Только при me_hub ON.
        if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
            $inline_keyboard[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        }

        $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];

        $imagePath = base_url('uploads/telegram/craft/many_medicinal_things.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
