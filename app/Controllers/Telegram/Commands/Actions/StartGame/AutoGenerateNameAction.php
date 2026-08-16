<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterNamesModel;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class AutoGenerateNameAction extends BaseAction
{
    protected $characterModel;
    protected $characterNamesModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->characterNamesModel = new CharacterNamesModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $currentName = $character['name'];
        if (empty($currentName) || in_array($currentName, ['Unknown Hero', 'NAN', null], true)) {
            // Получение случайного доступного имени
            $newNameRow = $this->characterNamesModel->where('status', 'free')->orderBy('RAND()')->first();

            if (!$newNameRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Нет доступных имен для автогенерации.',
                ]);
            }

            // Присваиваем персонажу новое имя и помечаем его как занятое
            $newName = $newNameRow['character_name'];
            $this->characterModel->update($character['id'], ['name' => $newName]);
            $this->characterNamesModel->update($newNameRow['id'], ['status' => 'occupied']);

            $messageName = "🎉 Тебе присвоено новое имя: *{$newName}*!";
        } else {
            // Если у персонажа уже есть корректное имя
            $messageName = "🆔 Твое текущее имя персонажа: *{$currentName}*.";
        }
        $message = "🤖 Это снова я – *Роби*!\n\n"
            . "{$messageName}\n\n"
            . "_Теперь пора переходить к делу, а именно пройти курс обучения для молодого бойца!_\n\n"
            . "Попутно ты узнаешь больше информации, получишь первый опыт, ресурсы и понимание механики, а также сеттинг игры.\n\n"
            . "_И помни, что пройдя обучение ты получишь знания и дополнительный опыт,что важно на старте._\n\n"
            . "🤖 *Приступаем!* ⬇️";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Без обучения', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Пройти обучение', 'callback_data' => 'getTrainedStart']
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран «имя присвоено → пройти обучение?» — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
