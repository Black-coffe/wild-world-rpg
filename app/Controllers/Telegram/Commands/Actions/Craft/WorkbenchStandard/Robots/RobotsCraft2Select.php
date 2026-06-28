<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class RobotsCraft2Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();

        $text = "*Ты в разделе 🤖 Роботы!* 🏭\n\n"
            . "Раздел крафта роботизированных вещей.\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "_Выбирай нужного робота и приступай к крафту_ 👇\n";

        $rows = [
            [
                ['text' => '🔍 Исследователь', 'callback_data' => 'robotExplorer'],
                ['text' => '⛏️ Добытчик', 'callback_data' => 'robotGatherer'],
            ],
        ];

        // V18 (ADR-049): T2-роботы (Разведчик/Промышленник) — гейт RoboticsWorkshop L2,
        // показываем только когда killswitch включён.
        if ((new \App\Services\Player\RobotService())->t2Enabled()) {
            $text .= "\n🔬 *T2 (нужна Мастерская робототехники 2 ур.):*\n";
            $rows[] = [
                ['text' => '🔭 Разведчик', 'callback_data' => 'robotScout'],
                ['text' => '🏭 Промышленник', 'callback_data' => 'robotIndustrial'],
            ];
        }

        $keyboard = ['inline_keyboard' => $rows];

        $imagePath = base_url('uploads/telegram/craft/standard/all_robots.jpg');

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
