<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class WorkbenchCraft1Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 🔬 Верстаки!* 🏭\n\n"
            . "Здесь собираются рабочие места — чем серьёзнее верстак, тем более сложные рецепты он открывает. В игре их два:\n\n"
            . "🔬 *Верстак 1* — базовый. Открывает *Стандартный крафт* (роботы, телепорты, броня, оружие, дроны). Нужна своя база.\n\n"
            . "🛠️ *Профессиональный верстак* (цех) — высший. Открывает *Профессиональный крафт* (топовое оружие, броня, медицина, утилиты, фракционные вещи). Нужны Доменная печь и Лаборатория 3-го уровня плюс 20-й уровень.\n\n"
            . "_Выбирай верстак и приступай к сборке_ 👇\n";

        // Два верстака в игре: Верстак 1 (базовый, callback workbenchOne) и
        // Профессиональный верстак (цех, callback workbenchProfessional → T3,
        // ADR-026). Отдельного «Верстака 2/3» нет — прогрессия Верстак 1 →
        // Профессиональный. Историческая кнопка-заглушка `workbenchTwo` удалена
        // (callback не имел handler'а); ярлык «Верстак 3 (T3)» убран, т.к.
        // создавал у игроков ложное ощущение пропущенного «второго» верстака.
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔬 Верстак 1', 'callback_data' => 'workbenchOne'],
                    ['text' => '🛠️ Профессиональный верстак', 'callback_data' => 'workbenchProfessional'],
                ],
                [
                    ['text' => '🔨 К общему крафту', 'callback_data' => 'generalCraft'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/workbench/workbenchSelect.jpg');

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
