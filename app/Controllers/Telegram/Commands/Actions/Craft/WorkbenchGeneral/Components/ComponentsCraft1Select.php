<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ComponentsCraft1Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 📐 Компоненты!* 🏭\n\n"
            . "Этот раздел для крафта компонентов.\n\n"
            . "_Выбирай нужный компонент и приступай к крафту_ 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔩 Металл фрагменты', 'callback_data' => 'metalFragments'],
                    ['text' => '🧵 Ткань', 'callback_data' => 'fabric'],
                ],
                [
                    ['text' => '🧱 Каменные блоки', 'callback_data' => 'stoneBlocks'],
                    ['text' => '🌿 Удобрение', 'callback_data' => 'fertilizer'],
                ],
                [
                    ['text' => '🪵 Древесные материалы', 'callback_data' => 'woodMaterials'],
                    ['text' => '🌱 Грунт', 'callback_data' => 'soil'],
                ],
                [
                    ['text' => '🪨 Угольные брикеты', 'callback_data' => 'charcoalBriquettes'],
                    ['text' => '🪟 Стекло пакеты', 'callback_data' => 'glassBags'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/components/A_cozy_workshop_with_tools_and_crafting_materials.jpg'); // Укажите актуальный путь к изображению

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
