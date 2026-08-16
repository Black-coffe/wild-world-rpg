<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class GeneralCraftingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();
        $text = "*Ты в разделе 🔨 Общий крафт!* 🏭\n\n"
            . "В этом разделе можно крафтить, где угодно и когда угодно.\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "_Выбирай направление крафта и если у тебя достаточно ресурсов, ты получишь нужную вещь_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💊 Лекарства', 'callback_data' => 'medicinesCraft1'],
                    ['text' => '🛠️ Инструменты', 'callback_data' => 'tools'],
                ],
                [
                    ['text' => '📐 Компоненты', 'callback_data' => 'componentsCraft'],
                    ['text' => '🔬 Верстаки', 'callback_data' => 'WorkbenchChoice'],
                ],
                [
                    ['text' => '🗓 Сезонный крафт', 'callback_data' => 'seasonalCraft'],
                    ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
                ],
                // 2026-08-16: у костра теперь ДВЕ двери — горячее и консервы (экран
                // разбит надвое, иначе подпись не влезала в лимит фото). Обе живут
                // здесь, а не только перекрёстной ссылкой друг на друга: иначе вход
                // в консервы подтверждал бы сам себя (гейт CraftRecipeReachabilityTest).
                // Тройка — из самых коротких подписей, чтобы не переносилась на 375px.
                [
                    ['text' => '🔥 Костёр', 'callback_data' => 'cook'],
                    // Литералом, а не константой: гейт достижимости ищет callback
                    // именно строкой в кавычках (через константу дверь для него невидима).
                    ['text' => '🥫 Консервы', 'callback_data' => 'cookPreserves'],
                    ['text' => '🛡 Защита', 'callback_data' => 'defenseCraft'],
                ],
            ]
        ];


        $imagePath = base_url('uploads/telegram/craft/general_crafting_img.png'); // Укажите актуальный путь к изображению

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
