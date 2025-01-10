<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;

class FinishAllTasksAction
{
    protected $callbackQuery;
    protected $characterModel;
    protected $characterTaskModel;
    protected $telegramUserModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterModel = new CharacterModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $callbackData = $this->callbackQuery->getData();

        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден.',
            ]);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
            ]);
        }

        if ($callbackData == 'finishAllTasks') {
            return $this->confirmFinishAllTasks($chatId);
        } elseif ($callbackData == 'finishAllTasks_yes') {
            return $this->finishAllTasks($character, $chatId);
        }
    }

    protected function confirmFinishAllTasks($chatId): ServerResponse
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Да ✅', 'callback_data' => 'finishAllTasks_yes'],
                    ['text' => 'Нет ❌', 'callback_data' => 'character']
                ]
            ]
        ];
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => "*Ситуации и выживание бывают разными...* \n\nИногда лучше всё бросить и бежать, нежели потерять и ресурсы, и своё здоровье. 🏃‍♂️💔\n\nНо решение нужно принимать взвешенно и стратегически. 🤔🗺️ Все задачи и действия будут анулированы и прекращены. Вы согласны? ✅❌",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    protected function finishAllTasks($character, $chatId): ServerResponse
    {
        $tasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('status', 'in_work')
            ->findAll();

        foreach ($tasks as $task) {
            $this->characterTaskModel->update($task['id'], ['status' => 'interrupted']);
        }

        // Обновляем характеристики персонажа, проверяя, чтобы не уйти в минус
        $updatedCharacterData = [
            'experience' => max(0.1, $character['experience'] - 0.5),
            'health' => max(0.1, $character['health'] - 1),
            'strength' => max(0.1, $character['strength'] - 0.25),
            'agility' => max(0.1, $character['agility'] - 0.5),
            'intellect' => max(0.1, $character['intellect'] - 0.5),
            'tired' => max(0.1, $character['tired'] - 0.2),
        ];
        $this->characterModel->update($character['id'], $updatedCharacterData);

        $text = "*Все ваши задачи были прерваны.* 😔\n\n"
            . "Вы потеряли немного в параметрах:\n"
            . "- 🌟 Опыт: -0,5\n"
            . "- 💖 Здоровье: -1\n"
            . "- 💪 Сила: -0,25\n"
            . "- 🤸‍♂️ Ловкость: -0,5\n"
            . "- 🧠 Интеллект: -0,5\n"
            . "- 🥱 Выносливость: -0,2\n\n"
            . "Думаю, это было продуманное и верное решение. Лучше потерять ресурсы, нежели потерять жизнь! 💡\n\n"
            . "*Не падай духом!* Выживание - это искусство принятия трудных решений и учеба на собственных ошибках. Всякий опыт ценен, а каждая неудача приближает тебя к пониманию, как стать сильнее и мудрее. 💪📚\n\n"
            . "*Вперед, к новым вызовам!* С каждым днем ты становишься только лучше. 🚀🌈";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🚜 Переехать', 'callback_data' => 'move'],

                ],
                [
                    ['text' => '🏕️ Окопаться', 'callback_data' => 'entrench'],
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],

                ],
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '📜 Выполнить квест', 'callback_data' => 'quest'],
                ]
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId() // Исправлено
        ]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);
    }
}
