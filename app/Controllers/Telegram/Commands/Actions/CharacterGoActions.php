<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\TelegramUserModel;
use App\Models\ClaimedCellModel; // <-- Нужно для проверки базы

class CharacterGoActions extends BaseAction
{
    protected $claimedCellModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel = new ClaimedCellModel(); // Для проверки базы
    }

    public function handle(): ServerResponse
    {
        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Шаг 1: Поиск пользователя в базе
        $telegramUserModel = new TelegramUserModel();
        $user = $telegramUserModel->where('telegram_id', $telegramUserId)->first();

        // Получаем ID персонажа
        $character_id = $telegramUserModel->getCharacterIdByTelegramId($telegramUserId);

        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных.',
            ]);
        }

        // Проверяем, есть ли у этого игрока активная база
        // (claimed_cells: status='active')
        $hasBase = $this->claimedCellModel
            ->where('character_id', $character_id)
            ->where('status', 'active')
            ->first();

        // Текст сообщения
        $text = "*Привет, герой! 🙋‍♂️* 👋\n\n"
            . "**Скучно сидеть сложа руки? Не беда!** 🥱\n\n"
            . "**Мы найдем работу для самых трудолюбивых героев!** 💪\n\n"
            . "**Выбирай, чем хочешь заняться сегодня:**\n\n"
            . "**Пусть тебе сопутствует удача!** 🍀\n\n"
            . "**P.S.** Делись своими достижениями в чате! 🗣️\n";

        // Формируем кнопки
        // Первая строка
        $keyboardButtons = [
            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ['text' => '🚜 Переехать',       'callback_data' => 'move'],
        ];

        // Вторая строка: "Окопаться" либо "Телепорт" — в зависимости от наличия базы
        if (!$hasBase) {
            // Нет базы => «Окопаться»
            $keyboardButtons[] = ['text' => '🏕️ Окопаться', 'callback_data' => 'entrench'];
        } else {
            // База есть => «Телепорт»
            $keyboardButtons[] = ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'];
        }

        // Добавим остальные кнопки (например, Квесты, Исследования...)
        $keyboardButtons[] = ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'];
        $keyboardButtons[] = ['text' => '🗺️ Изучить местность', 'callback_data' => 'march'];
        $keyboardButtons[] = ['text' => '⛏️ Добыть ресурсы',    'callback_data' => 'gather'];

        // Превращаем список кнопок в массив по строкам (2 кнопки в строке)
        $inlineKeyboard = array_chunk($keyboardButtons, 2);

        // Ответ на колбэк, чтобы убрать "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // #12 edit-in-place (ADR-018): меню действий персонажа — навигация → редактируем
        // текущее сообщение. editOrSend при любой ошибке edit упадёт обратно на новое.
        $imagePath = base_url('uploads/telegram/character_ready_to_act.png');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }
}
