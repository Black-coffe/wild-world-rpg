<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\ConsumableShelfService;
use Config\Consumables;

/**
 * «🍲 Провизия» — вторая полка из бывшей аптечки (docs/specs/pharmacy-split): еда и питьё,
 * ран не снимают. Тот же `crafted_items.type='drug'`-набор, что и у {@see PharmacyAction},
 * делит на полки {@see \App\Services\Player\ConsumableShelfService}; применение — тот же
 * `usePharmacy_<name_eng>` через {@see UsePharmacyAction}, здесь ничего не меняем.
 */
class ProvisionAction extends BaseAction
{
    protected CraftedItemsLogModel $craftedItemsLogModel;
    protected CraftedItemsModel $craftedItemsModel;

    public function __construct(CallbackQuery $callbackQuery)
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

        // Тот же drug-набор, что и у Аптечки — на полки его раскладывает
        // ConsumableShelfService::split(). Модель типизирована (returnType='array', именованные
        // алиасы select() — все ключи строковые), а не widening: узкий тип совпадает с рантаймом
        // (см. тот же приём в CraftTreeService::fetchAll()).
        /** @var list<array<string, mixed>> $craftedItemsLogs */
        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items_log.durability_time, crafted_items_log.durability_count AS log_charges, crafted_items.durability_count AS base_charges, crafted_items.name_rus, crafted_items.name_eng, crafted_items.character_boost')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where([
                'crafted_items_log.character_id' => $character['id'],
                'crafted_items.type' => 'drug'
            ])
            ->where('crafted_items_log.quantity >', 0)
            ->findAll();

        $shelfService  = new ConsumableShelfService();
        $split         = $shelfService->split($craftedItemsLogs);
        $provision     = $split[Consumables::SHELF_PROVISION];
        $medicineCount = count($split[Consumables::SHELF_MEDICINE]);

        // Если еды нет, предлагаем перейти к готовке. Кнопка на «Аптечку» остаётся видна и
        // здесь (UX-discoverability) — своя пустая полка не тупик.
        if (empty($provision)) {
            $text = "К сожалению, у тебя нет провизии! Нужно её сначала приготовить: "
                . "🔨 Крафт → 🔥 Костёр.";
            $inline_keyboard = [
                ['text' => "💊 Аптечка ({$medicineCount})", 'callback_data' => 'pharmacy'],
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ];
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

        // Есть провизия. Раздела активных ран здесь нет — еда состояний не снимает.
        $screen = $shelfService->screen(Consumables::SHELF_PROVISION, $provision);

        $text            = $screen['text'] . "\n_Выбери снизу, что ты будешь есть или пить:_ 👇";
        $inline_keyboard = $screen['buttons'];

        // UX-discoverability: сосед виден всегда, даже когда там пусто.
        $inline_keyboard[] = ['text' => "💊 Аптечка ({$medicineCount})", 'callback_data' => 'pharmacy'];
        $inline_keyboard[] = [
            'text' => '🧑‍🌾 Действия 🛠️',
            'callback_data' => 'characterActions'
        ];
        if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
            $inline_keyboard[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        }

        $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Файл проверяем до encodeFile — иначе fopen отсутствующего пути роняет экран целиком
        // (public/uploads/telegram/craft/ доезжает только деплоем, локально может быть пуст).
        $imageRel = 'uploads/telegram/craft/cooking/campfire_hot.jpg';
        if (is_file(FCPATH . $imageRel)) {
            return \App\Services\Notifications\MediaSender::sendPhotoOrText([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'photo' => Request::encodeFile(base_url($imageRel)),
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
